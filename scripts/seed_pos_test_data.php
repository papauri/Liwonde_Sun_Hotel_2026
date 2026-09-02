<?php
/**
 * scripts/seed_pos_test_data.php
 *
 * Seed TEST data so the POS -> stock -> finance chain can actually be exercised:
 * restaurant tables, suppliers, ingredients, opening stock batches and recipes
 * for the 187 menu items imported by scripts/import_menu_to_pos.php.
 *
 * This is demo data, not real inventory. Every row it creates is tagged
 * SEED_TAG in its notes column so --purge can remove exactly what it added and
 * nothing else.
 *
 * Modelling
 * ---------
 *   Drinks  — a bought-in drink IS the stock item. Each drink gets its own
 *             ingredient (unit "each") and a 1-per-portion recipe, so selling a
 *             Carlsberg decrements Carlsberg. Cost is set at 55% of sell price.
 *   Food    — plated dishes consume a shared pantry. A modest pantry is created
 *             and each dish is given a plausible 2-4 ingredient recipe chosen by
 *             its category, with a keyword override for obvious cases (pizza,
 *             burger, chips...). These quantities are indicative, NOT costed
 *             recipes — replace them with real ones in Admin -> Stock -> Recipes.
 *
 * Opening batches are created for every ingredient so FIFO has real stock to
 * draw from. Without them the first sale triggers
 * ensureStockBatchCoverageForDeduction(), which invents a TMP-R- batch and makes
 * stock figures fictional.
 *
 * Usage (CLI only):
 *   php scripts/seed_pos_test_data.php           # dry run — prints the plan
 *   php scripts/seed_pos_test_data.php --run     # apply
 *   php scripts/seed_pos_test_data.php --purge   # remove everything it seeded
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Forbidden: CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

const SEED_TAG = '[SEED:pos-test-data]';

$args  = array_slice($argv, 1);
$apply = in_array('--run', $args, true);
$purge = in_array('--purge', $args, true);

// ---------------------------------------------------------------------------
// Definitions
// ---------------------------------------------------------------------------

/** Dining room: table number => seats. */
$TABLES = [
    '1' => 2, '2' => 2, '3' => 4, '4' => 4, '5' => 4, '6' => 4,
    '7' => 6, '8' => 6, '9' => 6, '10' => 8,
    'T1' => 4, 'T2' => 4, 'T3' => 6, 'VIP' => 10,
];

$SUPPLIERS = [
    ['Liwonde Fresh Produce', 'Chikondi Banda', '+265 991 000 111', 2],
    ['Southern Bottlers Ltd',  'Grace Phiri',    '+265 991 000 222', 3],
    ['Zomba Meat & Poultry',   'James Mwale',    '+265 991 000 333', 4],
];

/** Food pantry: name => [category, unit, cost per unit MWK, opening qty, reorder point]. */
$PANTRY = [
    'Chicken Breast'    => ['Meat & Poultry', 'kg',   9500, 40,  10],
    'Beef Rump'         => ['Meat & Poultry', 'kg',  14000, 30,   8],
    'Chambo Fillet'     => ['Fish',           'kg',  12000, 25,   6],
    'Bacon'             => ['Meat & Poultry', 'kg',  11000, 10,   3],
    'Eggs'              => ['Dairy & Eggs',   'each',  350, 300, 60],
    'Milk'              => ['Dairy & Eggs',   'l',    1800, 60,  15],
    'Butter'            => ['Dairy & Eggs',   'kg',   8500, 12,   3],
    'Cheddar Cheese'    => ['Dairy & Eggs',   'kg',  12500, 15,   4],
    'Fresh Cream'       => ['Dairy & Eggs',   'l',    4200, 10,   3],
    'Ice Cream'         => ['Frozen',         'l',    5200, 20,   5],
    'Rice'              => ['Dry Goods',      'kg',   2600, 80,  20],
    'Pasta'             => ['Dry Goods',      'kg',   3200, 40,  10],
    'Potatoes'          => ['Vegetables',     'kg',   1400, 90,  25],
    'Maize Flour'       => ['Dry Goods',      'kg',   1500, 70,  20],
    'Bread Loaf'        => ['Bakery',         'each', 1600, 40,  12],
    'Burger Bun'        => ['Bakery',         'each',  700, 80,  20],
    'Pizza Base'        => ['Bakery',         'each', 1500, 60,  15],
    'Wheat Flour'       => ['Dry Goods',      'kg',   1900, 50,  12],
    'Tomatoes'          => ['Vegetables',     'kg',   1800, 45,  12],
    'Onions'            => ['Vegetables',     'kg',   1500, 45,  12],
    'Garlic'            => ['Vegetables',     'kg',   4500,  8,   2],
    'Mixed Vegetables'  => ['Vegetables',     'kg',   2200, 35,  10],
    'Lettuce'           => ['Vegetables',     'kg',   2400, 12,   4],
    'Mushrooms'         => ['Vegetables',     'kg',   6500, 10,   3],
    'Cooking Oil'       => ['Pantry',         'l',    4800, 40,  10],
    'Tomato Paste'      => ['Pantry',         'kg',   3600, 12,   3],
    'Curry Powder'      => ['Pantry',         'kg',   7200,  6,   2],
    'Salt'              => ['Pantry',         'kg',    900, 15,   4],
    'Black Pepper'      => ['Pantry',         'kg',  11000,  4,   1],
    'Sugar'             => ['Pantry',         'kg',   2100, 40,  10],
    'Coffee Beans'      => ['Beverage Base',  'kg',  14500, 12,   3],
    'Tea Bags'          => ['Beverage Base',  'each',  120, 800, 200],
];

/**
 * Food recipe templates: category slug => [ingredient => qty per portion].
 * Indicative quantities for demo purposes only.
 */
$BY_CATEGORY = [
    'breakfast'                => ['Eggs' => 2, 'Bacon' => 0.08, 'Bread Loaf' => 0.25, 'Butter' => 0.02],
    'starter'                  => ['Mushrooms' => 0.12, 'Fresh Cream' => 0.08, 'Bread Loaf' => 0.2, 'Butter' => 0.02],
    'chicken-corner'           => ['Chicken Breast' => 0.28, 'Cooking Oil' => 0.03, 'Onions' => 0.06, 'Rice' => 0.15],
    'meat-corner'              => ['Beef Rump' => 0.3, 'Cooking Oil' => 0.03, 'Onions' => 0.06, 'Potatoes' => 0.2],
    'fish-corner'              => ['Chambo Fillet' => 0.3, 'Cooking Oil' => 0.03, 'Salt' => 0.005, 'Potatoes' => 0.2],
    'pasta-corner'             => ['Pasta' => 0.2, 'Tomato Paste' => 0.06, 'Cheddar Cheese' => 0.05, 'Garlic' => 0.01],
    'burger-corner'            => ['Beef Rump' => 0.2, 'Burger Bun' => 1, 'Lettuce' => 0.03, 'Potatoes' => 0.2],
    'pizza-corner'             => ['Pizza Base' => 1, 'Cheddar Cheese' => 0.12, 'Tomato Paste' => 0.07],
    'snack-corner'             => ['Potatoes' => 0.25, 'Cooking Oil' => 0.04, 'Salt' => 0.004],
    'indian-corner'            => ['Chicken Breast' => 0.25, 'Curry Powder' => 0.02, 'Onions' => 0.08, 'Rice' => 0.18],
    'liwonde-sun-specialities' => ['Beef Rump' => 0.3, 'Mixed Vegetables' => 0.15, 'Maize Flour' => 0.2],
    'extras'                   => ['Potatoes' => 0.2, 'Cooking Oil' => 0.03],
    'desserts'                 => ['Ice Cream' => 0.15, 'Sugar' => 0.03, 'Fresh Cream' => 0.05],
];

/** Keyword overrides win over the category template. */
$BY_KEYWORD = [
    'nsima'   => ['Maize Flour' => 0.25, 'Salt' => 0.005],
    'chips'   => ['Potatoes' => 0.3, 'Cooking Oil' => 0.05, 'Salt' => 0.004],
    'salad'   => ['Lettuce' => 0.1, 'Tomatoes' => 0.08, 'Onions' => 0.03],
    'rice'    => ['Rice' => 0.2, 'Salt' => 0.004, 'Cooking Oil' => 0.01],
    'coffee'  => ['Coffee Beans' => 0.018, 'Milk' => 0.1, 'Sugar' => 0.01],
    'tea'     => ['Tea Bags' => 1, 'Milk' => 0.08, 'Sugar' => 0.01],
    'omelette' => ['Eggs' => 3, 'Butter' => 0.015, 'Cheddar Cheese' => 0.03],
    'soup'    => ['Mushrooms' => 0.1, 'Fresh Cream' => 0.08, 'Bread Loaf' => 0.2],
    'burger'  => ['Beef Rump' => 0.2, 'Burger Bun' => 1, 'Lettuce' => 0.03, 'Potatoes' => 0.2],
    'pizza'   => ['Pizza Base' => 1, 'Cheddar Cheese' => 0.12, 'Tomato Paste' => 0.07],
];

// ---------------------------------------------------------------------------
// Purge
// ---------------------------------------------------------------------------
if ($purge) {
    $pdo->beginTransaction();
    try {
        $ids = $pdo->query("SELECT id FROM stock_ingredients WHERE notes LIKE " . $pdo->quote('%' . SEED_TAG . '%'))
                   ->fetchAll(PDO::FETCH_COLUMN);
        $n = ['recipe_ingredients' => 0, 'recipes' => 0, 'batches' => 0, 'ingredients' => 0, 'tables' => 0, 'suppliers' => 0];

        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("DELETE FROM stock_recipe_ingredients WHERE ingredient_id IN ($in)");
            $st->execute($ids);
            $n['recipe_ingredients'] = $st->rowCount();

            $st = $pdo->prepare("DELETE FROM stock_batches WHERE ingredient_id IN ($in)");
            $st->execute($ids);
            $n['batches'] = $st->rowCount();

            $st = $pdo->prepare("DELETE FROM stock_ingredients WHERE id IN ($in)");
            $st->execute($ids);
            $n['ingredients'] = $st->rowCount();
        }
        // Recipes left with no ingredient lines are ours to remove.
        $n['recipes'] = $pdo->exec(
            "DELETE sr FROM stock_recipes sr
             LEFT JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
             WHERE sri.id IS NULL AND sr.notes LIKE " . $pdo->quote('%' . SEED_TAG . '%')
        );
        $st = $pdo->prepare("DELETE FROM restaurant_tables WHERE notes LIKE ?");
        $st->execute(['%' . SEED_TAG . '%']);
        $n['tables'] = $st->rowCount();

        $st = $pdo->prepare("DELETE FROM stock_suppliers WHERE notes LIKE ?");
        $st->execute(['%' . SEED_TAG . '%']);
        $n['suppliers'] = $st->rowCount();

        $pdo->commit();
        echo "Purged seeded data:\n";
        foreach ($n as $k => $v) printf("  %-20s %d\n", $k, $v);
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "ROLLED BACK: " . $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Plan
// ---------------------------------------------------------------------------
$items = $pdo->query(
    "SELECT mi.id, mi.item_name, mi.price, mi.station, mc.slug
     FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
     ORDER BY mi.id"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$items) {
    fwrite(STDERR, "No menu_items found — run scripts/import_menu_to_pos.php first.\n");
    exit(1);
}

$drinks = array_values(array_filter($items, fn($i) => $i['station'] !== 'kitchen'));
$food   = array_values(array_filter($items, fn($i) => $i['station'] === 'kitchen'));

/**
 * Resolve a food dish to its ingredient list.
 *
 * After the keyword/category template is chosen, the protein is corrected from
 * the dish name: "Chicken Spice Burger" matches the burger template, which is
 * beef, so without this it would consume Beef Rump. Demo data is only useful if
 * it is plausible.
 */
function recipe_for(array $item, array $byKeyword, array $byCategory): array
{
    $name = strtolower((string)$item['item_name']);

    $ing = null;
    foreach ($byKeyword as $kw => $tpl) {
        if (str_contains($name, $kw)) { $ing = $tpl; break; }
    }
    $ing ??= $byCategory[$item['slug']] ?? ['Mixed Vegetables' => 0.15, 'Cooking Oil' => 0.02];

    $proteins = ['Beef Rump', 'Chicken Breast', 'Chambo Fillet'];
    $wanted = null;
    if (str_contains($name, 'chicken')) {
        $wanted = 'Chicken Breast';
    } elseif (str_contains($name, 'fish') || str_contains($name, 'chambo')) {
        $wanted = 'Chambo Fillet';
    } elseif (str_contains($name, 'beef') || str_contains($name, 'steak')) {
        $wanted = 'Beef Rump';
    }

    if ($wanted !== null && !isset($ing[$wanted])) {
        $swapped = false;
        foreach ($proteins as $p) {
            if ($p !== $wanted && isset($ing[$p])) {
                $ing[$wanted] = $ing[$p];
                unset($ing[$p]);
                $swapped = true;
                break;
            }
        }
        // Template carried no protein at all ("Fish & Chips" matches the chips
        // keyword, "Chicken Alfredo" the pasta category). Add the named protein
        // rather than shipping a fish dish with no fish in it.
        if (!$swapped) {
            $ing[$wanted] = $wanted === 'Chambo Fillet' ? 0.25 : 0.22;
        }
    }

    // Vegetarian dishes should not carry meat at all.
    if (str_contains($name, 'veg') && !str_contains($name, 'vegas')) {
        foreach ($proteins as $p) {
            if (isset($ing[$p])) {
                unset($ing[$p]);
                $ing['Mixed Vegetables'] = 0.18;
            }
        }
    }

    return $ing;
}

$existingTables = (int)$pdo->query("SELECT COUNT(*) FROM restaurant_tables")->fetchColumn();
$existingIngr   = (int)$pdo->query("SELECT COUNT(*) FROM stock_ingredients")->fetchColumn();
$existingRec    = (int)$pdo->query("SELECT COUNT(*) FROM stock_recipes")->fetchColumn();

echo "=== PLAN ===\n";
printf("  tables       : %d to create (currently %d)\n", count($TABLES), $existingTables);
printf("  suppliers    : %d\n", count($SUPPLIERS));
printf("  ingredients  : %d pantry + %d drink stock items = %d (currently %d)\n",
    count($PANTRY), count($drinks), count($PANTRY) + count($drinks), $existingIngr);
printf("  batches      : 1 opening batch per ingredient = %d\n", count($PANTRY) + count($drinks));
printf("  recipes      : %d food + %d drink = %d (currently %d)\n",
    count($food), count($drinks), count($items), $existingRec);
echo "\n  sample food recipes:\n";
foreach (array_slice($food, 0, 5) as $f) {
    $r = recipe_for($f, $BY_KEYWORD, $BY_CATEGORY);
    printf("    %-34s %s\n", mb_strimwidth($f['item_name'], 0, 33, '…'),
        implode(', ', array_map(fn($k, $v) => "$k x$v", array_keys($r), $r)));
}

if (!$apply) {
    echo "\nDRY RUN — nothing written. Re-run with --run to apply, --purge to remove.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------
$today = date('Y-m-d');
$pdo->beginTransaction();
try {
    // Suppliers
    $supIns = $pdo->prepare("INSERT INTO stock_suppliers (name, contact_name, phone, lead_time_days, is_active, notes) VALUES (?, ?, ?, ?, 1, ?)");
    $supplierIds = [];
    foreach ($SUPPLIERS as [$n, $c, $p, $lt]) {
        $supIns->execute([$n, $c, $p, $lt, SEED_TAG]);
        $supplierIds[] = (int)$pdo->lastInsertId();
    }

    // Tables
    $tabIns = $pdo->prepare("INSERT INTO restaurant_tables (table_number, capacity, is_active, display_order, notes) VALUES (?, ?, 1, ?, ?)");
    $ord = 0;
    foreach ($TABLES as $num => $cap) {
        $tabIns->execute([(string)$num, $cap, ++$ord, SEED_TAG]);
    }

    // Ingredients + opening batches
    $ingIns = $pdo->prepare(
        "INSERT INTO stock_ingredients (name, category, unit, current_quantity, min_quantity, cost_per_unit,
                                        reorder_point, par_level, lead_time_days, preferred_supplier_id, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $batIns = $pdo->prepare(
        "INSERT INTO stock_batches (ingredient_id, batch_number, quantity_received, quantity_remaining,
                                    cost_per_unit, supplier_name, received_date, status, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)"
    );

    $ingredientIds = [];
    $seq = 0;
    $addIngredient = function (string $name, string $cat, string $unit, float $cost, float $qty, float $reorder, ?int $supId, string $supName)
        use ($ingIns, $batIns, &$ingredientIds, &$seq, $today) {
        // The menu repeats a few drink names across categories (Cappuccino and
        // Hot Chocolate appear under both Coffee and Non-Alcoholic). They are the
        // same physical stock item, so reuse it rather than creating a second
        // ingredient that nothing would ever deduct from.
        if (isset($ingredientIds[$name])) {
            return $ingredientIds[$name];
        }
        $ingIns->execute([$name, $cat, $unit, $qty, $reorder, $cost, $reorder, $qty * 2, 3, $supId, SEED_TAG]);
        $id = (int)$GLOBALS['pdo']->lastInsertId();
        $ingredientIds[$name] = $id;
        $batIns->execute([$id, sprintf('SEED-%04d', ++$seq), $qty, $qty, $cost, $supName, $today, SEED_TAG]);
        return $id;
    };

    foreach ($PANTRY as $name => [$cat, $unit, $cost, $qty, $reorder]) {
        $supIdx = str_contains(strtolower($cat), 'meat') || str_contains(strtolower($cat), 'fish') ? 2 : 0;
        $addIngredient($name, $cat, $unit, (float)$cost, (float)$qty, (float)$reorder,
            $supplierIds[$supIdx], $SUPPLIERS[$supIdx][0]);
    }
    foreach ($drinks as $d) {
        $cost = round(((float)$d['price']) * 0.55, 2);
        $addIngredient((string)$d['item_name'], 'Beverages', 'each', $cost, 48.0, 12.0,
            $supplierIds[1], $SUPPLIERS[1][0]);
    }

    // Recipes
    $recIns  = $pdo->prepare("INSERT INTO stock_recipes (menu_item_id, menu_type, portions_per_recipe, notes) VALUES (?, ?, 1, ?)");
    $riIns   = $pdo->prepare("INSERT INTO stock_recipe_ingredients (recipe_id, ingredient_id, quantity_per_portion, yield_percent) VALUES (?, ?, ?, 100.00)");
    $recipes = 0; $lines = 0; $unmapped = 0;

    foreach ($items as $it) {
        $isDrink = $it['station'] !== 'kitchen';
        $ing = $isDrink
            ? [(string)$it['item_name'] => 1.0]
            : recipe_for($it, $BY_KEYWORD, $BY_CATEGORY);

        $recIns->execute([(int)$it['id'], (string)$it['slug'], SEED_TAG]);
        $rid = (int)$pdo->lastInsertId();
        $recipes++;
        foreach ($ing as $iname => $qty) {
            if (!isset($ingredientIds[$iname])) { $unmapped++; continue; }
            $riIns->execute([$rid, $ingredientIds[$iname], (float)$qty]);
            $lines++;
        }
    }

    $pdo->commit();
    printf("\nDone. suppliers=%d tables=%d ingredients=%d batches=%d recipes=%d recipe_lines=%d unmapped=%d\n",
        count($SUPPLIERS), count($TABLES), count($ingredientIds), count($ingredientIds), $recipes, $lines, $unmapped);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ROLLED BACK: " . $e->getMessage() . "\n");
    exit(1);
}
