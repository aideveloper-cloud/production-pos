<?php

use App\Models\Product;

$count = Product::query()->count();
echo "count={$count}".PHP_EOL;
foreach (Product::query()->orderBy('sku')->limit(50)->get(['sku', 'title', 'domain', 'base_uom', 'spoolman_enabled']) as $p) {
    echo ($p->sku ?: '-').' | '.$p->title.' | '.($p->domain ?? 'FG').' | '.($p->base_uom ?: '-').PHP_EOL;
}
