<?php
// src/SyncService.php

declare(strict_types=1);

class SyncService
{
    private PDO $pdo;
    private PcoClient $pco;

    public function __construct(PDO $pdo, PcoClient $pco)
    {
        $this->pdo = $pdo;
        $this->pco = $pco;
    }

    /**
     * Build a preview of what a QBO Deposit would look like for Stripe
     * donations completed in [ $sinceUtc, $untilUtc ].
     *
     * "Stripe donations" here are:
     *  - payment_method in ['card','ach']
     *  - payment_status == 'succeeded'
     *  - completed_at non-null and within the window
     *
     * For each fund we produce:
     *  - total gross
     *  - total Stripe fee (positive number)
     *  - net (gross - fee)
     */
    public function buildDepositPreview(DateTimeImmutable $sinceUtc, ?DateTimeImmutable $untilUtc = null): array
    {
        $fundMappings = $this->loadFundMappings(); // [fund_id => row]
        $nowUtc       = $untilUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Fetch up to 100 newest donations, ordered by completed_at
        $resp = $this->pco->listDonations([
            'per_page' => 100,
            'order'    => '-completed_at',
            'include'  => 'designations',
        ]);

        $fundTotals         = []; // [fund_id => aggregate]
        $donationCount      = 0;
        $processedDonations = 0;
        $skippedOffline     = 0;
        $skippedUnmapped    = [];

        // Index "included" designations by type:id
        $includedByKey = [];
        if (!empty($resp['included']) && is_array($resp['included'])) {
            foreach ($resp['included'] as $inc) {
                $type = $inc['type'] ?? '';
                $id   = $inc['id']   ?? '';
                if ($type && $id) {
                    $includedByKey["{$type}:{$id}"] = $inc;
                }
            }
        }

        if (empty($resp['data']) || !is_array($resp['data'])) {
            return [
                'since'               => $sinceUtc,
                'until'               => $nowUtc,
                'funds'               => [],
                'total_gross'         => 0.0,
                'total_fee'           => 0.0,
                'total_net'           => 0.0,
                'donation_count'      => 0,
                'processed_donations' => 0,
                'skipped_offline'     => 0,
                'skipped_unmapped'    => $skippedUnmapped,
            ];
        }

        foreach ($resp['data'] as $donation) {
            $id    = $donation['id'] ?? '??';
            $attrs = $donation['attributes'] ?? [];
            $rels  = $donation['relationships'] ?? [];

            $completedAtStr = $attrs['completed_at'] ?? null;
            if (!$completedAtStr) {
                // Not yet paid out by Stripe
                continue;
            }

            try {
                $completedAt = new DateTimeImmutable($completedAtStr);
            } catch (Throwable $e) {
                continue;
            }

            // Only donations whose completed_at falls in our window
            if ($completedAt < $sinceUtc || $completedAt > $nowUtc) {
                continue;
            }

            $paymentStatus = strtolower((string)($attrs['payment_status'] ?? ''));
            $paymentMethod = strtolower((string)($attrs['payment_method'] ?? ''));
            $refunded      = (bool)($attrs['refunded'] ?? false);

            // Only succeeded, non-refunded online donations
            if ($paymentStatus !== 'succeeded' || $refunded) {
                continue;
            }

            $onlineMethods = ['card', 'ach'];
            if (!in_array($paymentMethod, $onlineMethods, true)) {
                $skippedOffline++;
                continue;
            }

            $donationCount++;

            $donationAmountCents = (int)($attrs['amount_cents'] ?? 0);
            $feeCentsRaw         = (int)($attrs['fee_cents'] ?? 0);
            $feeCentsAbs         = abs($feeCentsRaw);

            // Collect designations: fund_id + amount_cents
            $designationRefs = $rels['designations']['data'] ?? [];
            if (empty($designationRefs) || !is_array($designationRefs)) {
                $skippedUnmapped[] = [
                    'donation_id'   => $id,
                    'reason'        => 'No designations',
                    'amount_cents'  => $donationAmountCents,
                    'payment_method'=> $paymentMethod,
                ];
                continue;
            }

            $designationDetails     = [];
            $designationTotalCents = 0;

            foreach ($designationRefs as $desRef) {
                $dtype = $desRef['type'] ?? '';
                $did   = $desRef['id']   ?? '';
                $key   = "{$dtype}:{$did}";
                if (!isset($includedByKey[$key])) {
                    continue;
                }

                $des      = $includedByKey[$key];
                $desAttrs = $des['attributes'] ?? [];
                $desAmt   = (int)($desAttrs['amount_cents'] ?? 0);

                $desFundId = null;
                if (!empty($des['relationships']['fund']['data']['id'])) {
                    $desFundId = (string)$des['relationships']['fund']['data']['id'];
                }

                if ($desFundId === null || $desAmt === 0) {
                    continue;
                }

                $designationDetails[] = [
                    'fund_id'      => $desFundId,
                    'amount_cents' => $desAmt,
                ];

                $designationTotalCents += $desAmt;
            }

            if ($designationTotalCents <= 0 || empty($designationDetails)) {
                $skippedUnmapped[] = [
                    'donation_id'   => $id,
                    'reason'        => 'Designations total zero or missing',
                    'amount_cents'  => $donationAmountCents,
                    'payment_method'=> $paymentMethod,
                ];
                continue;
            }

            $processedDonations++;

            // Split fee across designations proportionally
            $remainingFeeCents = $feeCentsAbs;
            $desCount          = count($designationDetails);

            foreach ($designationDetails as $idx => $des) {
                $fundId = $des['fund_id'];
                $gross  = $des['amount_cents'];

                if ($gross <= 0) {
                    continue;
                }

                // Determine fee share for this fund
                $feeShareCents = 0;
                if ($feeCentsAbs > 0) {
                    if ($idx === $desCount - 1) {
                        // Last designation gets the remainder
                        $feeShareCents = $remainingFeeCents;
                    } else {
                        $ratio         = $gross / $designationTotalCents;
                        $feeShareCents = (int)round($feeCentsAbs * $ratio);
                        $remainingFeeCents -= $feeShareCents;
                    }
                }

                // Check fund mapping
                if (!isset($fundMappings[$fundId])) {
                    $skippedUnmapped[] = [
                        'donation_id'   => $id,
                        'reason'        => "Fund {$fundId} not mapped",
                        'fund_id'       => $fundId,
                        'amount_cents'  => $gross,
                        'payment_method'=> $paymentMethod,
                    ];
                    continue;
                }

                $map = $fundMappings[$fundId];
                $key = (string)$fundId;

                if (!isset($fundTotals[$key])) {
                    $fundTotals[$key] = [
                        'pco_fund_id'       => $fundId,
                        'pco_fund_name'     => $map['pco_fund_name'],
                        'qbo_class_name'    => $map['qbo_class_name'],
                        'qbo_location_name' => $map['qbo_location_name'],
                        'gross_cents'       => 0,
                        'fee_cents'         => 0,
                    ];
                }

                $fundTotals[$key]['gross_cents'] += $gross;
                $fundTotals[$key]['fee_cents']   += $feeShareCents;
            }
        }

        // Convert cents to dollars
        $totalGrossCents = 0;
        $totalFeeCents   = 0;

        $fundsOut = [];
        foreach ($fundTotals as $fundId => $row) {
            $grossCents = (int)$row['gross_cents'];
            $feeCents   = (int)$row['fee_cents'];
            $netCents   = $grossCents - $feeCents;

            $totalGrossCents += $grossCents;
            $totalFeeCents   += $feeCents;

            $fundsOut[] = [
                'pco_fund_id'       => $row['pco_fund_id'],
                'pco_fund_name'     => $row['pco_fund_name'],
                'qbo_class_name'    => $row['qbo_class_name'],
                'qbo_location_name' => $row['qbo_location_name'],
                'gross'             => $grossCents / 100.0,
                'fee'               => $feeCents / 100.0,
                'net'               => $netCents / 100.0,
            ];
        }

        usort($fundsOut, function (array $a, array $b): int {
            return strcmp($a['pco_fund_name'], $b['pco_fund_name']);
        });

        return [
            'since'               => $sinceUtc,
            'until'               => $nowUtc,
            'funds'               => $fundsOut,
            'total_gross'         => $totalGrossCents / 100.0,
            'total_fee'           => $totalFeeCents / 100.0,
            'total_net'           => ($totalGrossCents - $totalFeeCents) / 100.0,
            'donation_count'      => $donationCount,
            'processed_donations' => $processedDonations,
            'skipped_offline'     => $skippedOffline,
            'skipped_unmapped'    => $skippedUnmapped,
        ];
    }

    /**
     * Load fund mappings from DB keyed by pco_fund_id.
     */
    private function loadFundMappings(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM fund_mappings");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $fundId       = (string)$row['pco_fund_id'];
            $out[$fundId] = $row;
        }

        return $out;
    }
}
