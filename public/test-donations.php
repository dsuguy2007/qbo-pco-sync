<?php
declare(strict_types=1);



$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();
try {
    $pco = new PcoClient($config);

    // Latest 20 donations, newest first, including labels + designations
    $resp = $pco->listDonations([
        'per_page' => 20,
        'order'    => '-received_at',
        'include'  => 'labels,designations',
    ]);

    header('Content-Type: text/plain; charset=utf-8');

    echo "Raw donations (trimmed view)\n";
    echo "====================================\n\n";

    // Build index of included resources (labels, designations)
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

    if (empty($resp['data'])) {
        echo "No donations returned.\n";
        exit;
    }

    foreach ($resp['data'] as $donation) {
        $id    = $donation['id'] ?? '??';
        $attrs = $donation['attributes'] ?? [];
        $rels  = $donation['relationships'] ?? [];

        $receivedAt  = $attrs['received_at'] ?? 'N/A';
        $amountCents = $attrs['amount_cents'] ?? 0;
        $feeCents    = $attrs['fee_cents'] ?? 0;
        $status      = $attrs['payment_status'] ?? 'N/A';
        $method      = $attrs['payment_method'] ?? 'N/A';

        // Labels
        $labelNames = [];
        if (!empty($rels['labels']['data']) && is_array($rels['labels']['data'])) {
            foreach ($rels['labels']['data'] as $lblRef) {
                $ltype = $lblRef['type'] ?? '';
                $lid   = $lblRef['id']   ?? '';
                $key   = "{$ltype}:{$lid}";
                if (isset($includedByKey[$key])) {
                    $lAttrs = $includedByKey[$key]['attributes'] ?? [];
                    if (!empty($lAttrs['name'])) {
                        $labelNames[] = $lAttrs['name'];
                    }
                }
            }
        }

        // Designations (fund-level breakdown)
        $designationSummaries = [];
        if (!empty($rels['designations']['data']) && is_array($rels['designations']['data'])) {
            foreach ($rels['designations']['data'] as $desRef) {
                $dtype = $desRef['type'] ?? '';
                $did   = $desRef['id']   ?? '';
                $key   = "{$dtype}:{$did}";
                if (isset($includedByKey[$key])) {
                    $des      = $includedByKey[$key];
                    $desAttrs = $des['attributes'] ?? [];
                    $desAmt   = $desAttrs['amount_cents'] ?? null;

                    $desFundId = null;
                    if (!empty($des['relationships']['fund']['data']['id'])) {
                        $desFundId = $des['relationships']['fund']['data']['id'];
                    }

                    $designationSummaries[] = [
                        'fund_id'      => $desFundId,
                        'amount_cents' => $desAmt,
                    ];
                }
            }
        }

        echo "Donation ID: {$id}\n";
        echo "  received_at: {$receivedAt}\n";
        echo "  payment_status: {$status}\n";
        echo "  payment_method: {$method}\n";
        echo "  amount_cents: {$amountCents}\n";
        echo "  fee_cents: {$feeCents}\n";
        echo "  labels: " . implode(', ', $labelNames) . "\n";

        if (!empty($designationSummaries)) {
            echo "  designations:\n";
            foreach ($designationSummaries as $des) {
                echo "    - fund_id: {$des['fund_id']}, amount_cents: {$des['amount_cents']}\n";
            }
        } else {
            echo "  designations: (none)\n";
        }

        echo "\n";
    }

} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error talking to PCO donations endpoint:\n";
    echo $e->getMessage() . "\n";
}
