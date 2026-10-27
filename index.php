<?php
require_once __DIR__ . '/config/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$pdo = db();
$error = '';
$success = '';

// Safe one-time schema compatibility for existing installations.
$purchaseColumns = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'description'")->fetchAll();
if (!$purchaseColumns) {
    $pdo->exec('ALTER TABLE purchases ADD COLUMN description VARCHAR(255) NULL AFTER total');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add_purchase') {
            $part = trim((string)($_POST['part_name'] ?? ''));
            $price = filter_var($_POST['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
            $qty = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
            $description = trim((string)($_POST['description'] ?? ''));
            if ($part === '' || mb_strlen($part) > 180) throw new RuntimeException('Enter a part name (up to 180 characters).');
            if ($price === false || $price <= 0 || $price > 9999999999) throw new RuntimeException('Enter a valid unit price greater than zero.');
            if ($qty === false || $qty <= 0 || $qty > 999999 || round($qty, 3) != $qty) throw new RuntimeException('Enter a valid quantity (up to 3 decimal places).');
            if (mb_strlen($description) > 255) throw new RuntimeException('Description must be 255 characters or fewer.');
            $total = round($price * $qty, 2);
            $stmt = $pdo->prepare('INSERT INTO purchases (part_name, unit_price, quantity, total, description) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$part, number_format($price, 2, '.', ''), number_format($qty, 3, '.', ''), number_format($total, 2, '.', ''), $description === '' ? null : $description]);
            $success = 'Purchase added successfully.';
        } elseif ($action === 'add_payment') {
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $methods = ['Bank Transfer', 'Wallet', 'Mobile Banking', 'Cheque', 'Cash', 'Other'];
            $method = (string)($_POST['method'] ?? 'Cash');
            $description = trim((string)($_POST['description'] ?? ''));
            if ($amount === false || $amount <= 0 || $amount > 999999999999) throw new RuntimeException('Enter a valid payment amount greater than zero.');
            if (!in_array($method, $methods, true)) throw new RuntimeException('Choose a valid payment method.');
            if (mb_strlen($description) > 255) throw new RuntimeException('Description must be 255 characters or fewer.');
            $stmt = $pdo->prepare('INSERT INTO payments (amount, method, note) VALUES (?, ?, ?)');
            $stmt->execute([number_format($amount, 2, '.', ''), $method, $description === '' ? null : $description]);
            $success = 'Payment added successfully.';
        }
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
      catch (Throwable $ex) { $error = 'Could not save this record. Please check the values and try again.'; }
}

$totals = $pdo->query('SELECT COALESCE((SELECT SUM(total) FROM purchases),0) AS purchases, COALESCE((SELECT SUM(amount) FROM payments),0) AS paid')->fetch();
$totalPurchases = (float)$totals['purchases'];
$totalPaid = (float)$totals['paid'];
$balance = $totalPurchases - $totalPaid;
$recentSql = "SELECT * FROM (
 SELECT id, 'purchase' AS type, part_name AS title, unit_price, quantity, total AS amount, description, NULL AS method, created_at FROM purchases
 UNION ALL
 SELECT id, 'payment' AS type, 'Supplier payment' AS title, NULL AS unit_price, NULL AS quantity, amount, note AS description, method, created_at FROM payments
) records ORDER BY created_at DESC, id DESC LIMIT 7";
$recent = $pdo->query($recentSql)->fetchAll();
$totalRecords = (int)$pdo->query('SELECT (SELECT COUNT(*) FROM purchases) + (SELECT COUNT(*) FROM payments)')->fetchColumn();
function quantity_text($qty): string { return rtrim(rtrim(number_format((float)$qty, 3, '.', ''), '0'), '.'); }
function record_particular(array $r): string {
    if ($r['type'] === 'purchase') return quantity_text($r['quantity']) . ' × ' . $r['title'];
    return 'Payment' . (!empty($r['method']) ? ' (' . $r['method'] . ')' : '');
}
function record_description(array $r): string { $description = trim((string)($r['description'] ?? '')); return $description === '' ? '-' : $description; }
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#155eef"><title>JPW Khata</title><link rel="stylesheet" href="assets/css/style.css"><script src="assets/js/app.js" defer></script></head>
<body><main class="app-shell">
<header class="topbar"><div class="brand-and-menu"><button class="menu-toggle" id="menuToggle" type="button" aria-label="Open menu" aria-expanded="false">☰</button><h1>JPW Khata</h1></div><div class="topbar-actions"><a class="btn secondary" href="records.php">See All Records</a><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="btn quiet" type="submit">Log out</button></form></div></header>
<div class="sidebar-scrim" id="sidebarScrim"></div><aside class="side-menu" id="sideMenu" aria-label="Main menu"><div class="side-menu-heading"><strong>JPW Khata</strong><button type="button" id="menuClose" class="menu-close" aria-label="Close menu">×</button></div><a href="records.php">See All Records</a><a href="change-account.php">Change username and password</a><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button type="submit">Log out</button></form></aside>
<?php if($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?><?php if($success): ?><div class="alert success" role="status"><?=e($success)?></div><?php endif; ?>
<section class="summary-grid" aria-label="Account summary">
 <article class="summary-card balance-card"><div class="summary-label">Remaining to pay</div><div class="summary-value"><?=e(money($balance))?></div></article>
 <article class="summary-card purchase-summary"><div class="summary-label">Total Purchase</div><div class="summary-value"><?=e(money($totalPurchases))?></div></article>
 <article class="summary-card paid-summary"><div class="summary-label">Total Paid</div><div class="summary-value"><?=e(money($totalPaid))?></div></article>
</section>
<section class="panel purchase-panel"><div class="panel-title"><h2>Add Purchase</h2></div>
<form method="post" class="entry-form" id="purchaseForm"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_purchase">
 <label class="horizontal-field">Part name<input name="part_name" maxlength="180" placeholder="e.g. Brake shoe" required></label>
 <div class="two-fields"><label class="horizontal-field">Unit price (NPR)<input name="unit_price" type="number" min="0.01" max="9999999999" step="0.01" placeholder="0.00" required></label><label class="horizontal-field">Quantity<input name="quantity" type="number" min="0.001" max="999999" step="0.001" value="1" required></label></div>
 <label class="horizontal-field">Description <span class="optional">(optional)</span><input name="description" maxlength="255" placeholder="Add a note if needed"></label>
 <div class="total-preview"><span>Purchase total</span><strong id="lineTotal">रु 0.00</strong></div>
 <button class="btn primary full purchase-submit" type="submit">Add Purchase</button>
</form></section>
<details class="add-record-dropdown" id="addRecordDropdown"><summary><span>＋ Add record</span><span class="summary-arrow" aria-hidden="true">⌄</span></summary><div class="dropdown-content"><div class="dropdown-heading"><span class="icon-box green-box">↗</span><div><h2>Record payment</h2><p class="muted">Add money paid to the supplier</p></div></div>
<form method="post" class="entry-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_payment">
<label>Amount paid (NPR)<input name="amount" type="number" min="0.01" max="999999999999" step="0.01" placeholder="0.00" required></label>
<label>Payment method<select name="method"><option>Bank Transfer</option><option>Wallet</option><option>Mobile Banking</option><option>Cheque</option><option>Cash</option><option>Other</option></select></label>
<label>Description <span class="optional">(optional)</span><input name="description" maxlength="255" placeholder="Add a note if needed"></label>
<button class="btn success-btn full" type="submit">Save payment</button></form></div></details>
<section class="panel activity-panel"><div class="section-heading"><div><h2>Recent activity</h2><p class="muted">Latest 7 records</p></div><a class="btn secondary" href="records.php">View all (<?=e($totalRecords)?>)</a></div>
<?php if(!$recent): ?><div class="empty-state"><strong>No records yet</strong><p>Your purchases and payments will appear here.</p></div><?php else: ?><div class="table-scroll"><table class="records-table"><thead><tr><th>S.No.</th><th>Date / Time</th><th>Particular</th><th>Description</th><th class="amount-column">Amount</th></tr></thead><tbody>
<?php foreach($recent as $index=>$r): ?><tr><td class="serial-cell"><?=e($index+1)?></td><td class="date-cell"><strong><?=e(date('d M Y',strtotime($r['created_at'])))?></strong><span><?=e(date('g:i:s a',strtotime($r['created_at'])))?></span></td><td class="particular-cell"><?=e(record_particular($r))?></td><td class="description-cell"><?=e(record_description($r))?></td><td class="amount-cell <?= $r['type']==='purchase'?'red':'green' ?>"><?=e(money($r['amount']))?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<footer class="footer-note">Your records are saved in the local database. Make regular backups.</footer>
</main></body></html>
