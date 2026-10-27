<?php
require_once __DIR__ . '/config/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$pdo = db();
$purchaseColumns = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'description'")->fetchAll();
if (!$purchaseColumns) $pdo->exec('ALTER TABLE purchases ADD COLUMN description VARCHAR(255) NULL AFTER total');
// Ensure the requested payment methods are accepted on existing installations.
$methodColumn = $pdo->query("SHOW COLUMNS FROM payments LIKE 'method'")->fetch();
if ($methodColumn && strpos((string)$methodColumn['Type'], 'Bank Transfer') === false) {
    $pdo->exec("ALTER TABLE payments MODIFY method VARCHAR(40) NOT NULL DEFAULT 'Cash'");
}
function qty_records($qty): string { return rtrim(rtrim(number_format((float)$qty, 3, '.', ''), '0'), '.'); }
function particular_records(array $r): string { return $r['type'] === 'purchase' ? qty_records($r['quantity']) . ' x ' . $r['title'] : 'Payment' . (!empty($r['method']) ? ' (' . $r['method'] . ')' : ''); }
function desc_records(array $r): string { $d = trim((string)($r['description'] ?? '')); return $d === '' ? '-' : $d; }
function fetch_records(PDO $pdo, string $where = '', array $params = []): array {
    $sql = "SELECT * FROM (
      SELECT id, 'purchase' AS type, part_name AS title, unit_price, quantity, total AS amount, description, NULL AS method, created_at FROM purchases
      UNION ALL
      SELECT id, 'payment' AS type, 'Supplier payment' AS title, NULL AS unit_price, NULL AS quantity, amount, note AS description, method, created_at FROM payments
    ) records" . ($where ? ' WHERE ' . $where : '') . ' ORDER BY created_at DESC, id DESC';
    $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll();
}
// PDF download is generated server-side, filtered by the selected interval.
if (($_GET['action'] ?? '') === 'pdf') {
    $range = (string)($_GET['range'] ?? 'all'); $where = ''; $params = [];
    if ($range === '1m') $where = 'created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)';
    elseif ($range === '3m') $where = 'created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)';
    elseif ($range === 'custom') {
        $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? '');
        $validDate = static fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        if (!$validDate($from) || !$validDate($to) || $from > $to) { http_response_code(400); exit('Choose a valid date range.'); }
        $where = 'DATE(created_at) BETWEEN ? AND ?'; $params = [$from, $to];
    }
    $rows = fetch_records($pdo, $where, $params);
    $lines = [];
    $lines[] = 'JP WORKSHOP KHATA - TRANSACTION REPORT';
    $lines[] = 'Period: ' . ($range === 'all' ? 'All records' : ($range === '1m' ? 'Last 1 month' : ($range === '3m' ? 'Last 3 months' : ($from . ' to ' . $to))));
    $lines[] = 'Generated: ' . date('d M Y, g:i a');
    $lines[] = '';
    $lines[] = sprintf('%-4s %-17s %-28s %-22s %12s %12s', 'S.No', 'Date', 'Particular', 'Description', 'Debit (NPR)', 'Credit (NPR)');
    $lines[] = str_repeat('-', 101);
    $debit = 0; $credit = 0;
    foreach ($rows as $i => $r) {
        $isPurchase = $r['type'] === 'purchase';
        if ($isPurchase) $debit += (float)$r['amount']; else $credit += (float)$r['amount'];
        $date = date('d M Y g:i a', strtotime($r['created_at']));
        $part = particular_records($r); $desc = desc_records($r);
        $lines[] = sprintf('%-4s %-17s %-28s %-22s %12s %12s', (string)($i+1), $date, $part, $desc, $isPurchase ? number_format((float)$r['amount'],2,'.','') : '', !$isPurchase ? number_format((float)$r['amount'],2,'.','') : '');
    }
    $lines[] = str_repeat('-', 101);
    $lines[] = 'TOTALS: Debit ' . number_format($debit, 2) . ' NPR | Credit ' . number_format($credit, 2) . ' NPR | Net ' . number_format($debit-$credit, 2) . ' NPR';
    // Minimal self-contained PDF writer; ASCII-safe text ensures no external library is needed.
    $pages = array_chunk($lines, 42); if (!$pages) $pages = [[]];
    $objects = []; $fontId = 3 + count($pages) * 2; $kids = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    foreach ($pages as $i => $pageLines) {
        $pageId = 3 + $i*2; $contentId = $pageId+1; $kids[] = "$pageId 0 R";
        $stream = "BT\n/F1 8 Tf\n32 800 Td\n11 TL\n";
        foreach ($pageLines as $line) {
            $line = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $line) ?: $line;
            $line = preg_replace('/[^\x20-\x7E]/', '?', $line);
            $line = str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $line);
            $stream .= '(' . $line . ") Tj\nT*\n";
        }
        $stream .= 'ET';
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 842] /Resources << /Font << /F1 ' . $fontId . ' 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $objects[$contentId] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
    $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>'; ksort($objects);
    $pdf = "%PDF-1.4\n"; $offsets = [0]; $max = max(array_keys($objects));
    for ($i=1;$i<=$max;$i++) { $offsets[$i]=strlen($pdf); $pdf .= "$i 0 obj\n" . ($objects[$i] ?? '<<>>') . "\nendobj\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 " . ($max+1) . "\n0000000000 65535 f \n";
    for ($i=1;$i<=$max;$i++) $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
    $pdf .= "trailer\n<< /Size " . ($max+1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="jpw-khata-report-' . date('Y-m-d') . '.pdf"'); header('Content-Length: ' . strlen($pdf)); echo $pdf; exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'delete_selected') {
            $items = $_POST['records'] ?? [];
            if (!is_array($items) || count($items) < 1) throw new RuntimeException('Select at least one record to delete.');
            $pdo->beginTransaction(); $deleted = 0;
            foreach ($items as $item) {
                if (!is_string($item) || !preg_match('/^(purchase|payment):\d+$/', $item)) continue;
                [$type,$id] = explode(':',$item,2); $table = $type === 'purchase' ? 'purchases' : 'payments';
                $st = $pdo->prepare("DELETE FROM $table WHERE id = ?"); $st->execute([(int)$id]); $deleted += $st->rowCount();
            }
            $pdo->commit(); $_SESSION['records_notice'] = "$deleted record(s) deleted.";
        } elseif ($action === 'delete_all') {
            $confirm = (string)($_POST['confirm_delete_all'] ?? '');
            if ($confirm !== 'YES_DELETE_ALL') throw new RuntimeException('Delete-all confirmation was not received.');
            $pdo->beginTransaction(); $pdo->exec('DELETE FROM purchases'); $pdo->exec('DELETE FROM payments'); $pdo->commit();
            $_SESSION['records_notice'] = 'All purchase and payment records have been deleted.';
        }
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); $error = $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not delete records. Please try again.'; }
    if (!$error) { header('Location: records.php'); exit; }
}
$notice = (string)($_SESSION['records_notice'] ?? ''); unset($_SESSION['records_notice']);
$records = fetch_records($pdo);
$grouped = []; foreach ($records as $r) $grouped[date('Y-m-d', strtotime($r['created_at']))][] = $r;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#155eef"><title>All records · JPW Khata</title><link rel="stylesheet" href="assets/css/style.css"><script src="assets/js/app.js" defer></script><style>
.records-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin:14px 0}.records-toolbar .left,.records-toolbar .right{display:flex;gap:8px;flex-wrap:wrap}.delete-btn{background:#b42318;color:white}.pdf-btn{background:#155eef;color:white}.selection-cell{width:36px;text-align:center}.selection-cell input{width:18px;height:18px;accent-color:#155eef}.table-scroll{overflow-x:auto}.records-table{min-width:760px}.delete-bottom{display:flex;justify-content:flex-end;margin:24px 0 10px}.modal-backdrop{position:fixed;inset:0;background:#0f172acc;display:none;align-items:center;justify-content:center;padding:18px;z-index:999}.modal-backdrop.open{display:flex}.modal-box{width:min(100%,430px);background:#fff;border-radius:18px;padding:22px;box-shadow:0 24px 80px #0004;color:#172033}.modal-box h2{margin:0 0 8px}.modal-box p{color:#526078}.modal-options{display:grid;gap:12px;margin:18px 0}.modal-options label{display:flex;gap:10px;align-items:center;padding:11px;border:1px solid #e0e6ef;border-radius:10px}.modal-options input[type=radio]{width:18px;height:18px}.custom-dates{display:none;grid-template-columns:1fr 1fr;gap:10px}.custom-dates.visible{display:grid}.modal-actions{display:flex;gap:10px;margin-top:18px}.modal-actions button{flex:1}.select-note{font-size:13px;color:#667085}.record-actions-form{margin:0}.records-page .date-heading{display:flex;align-items:center;justify-content:space-between;gap:10px}.records-page .date-group{margin-bottom:16px}.records-page .amount-cell{font-weight:800}.records-page .date-cell span{display:block}@media(max-width:600px){.records-toolbar{align-items:stretch}.records-toolbar .left,.records-toolbar .right{display:grid;grid-template-columns:1fr;width:100%}.records-toolbar button,.records-toolbar a{width:100%;text-align:center}.delete-bottom button{width:100%}.custom-dates{grid-template-columns:1fr}.modal-actions{flex-direction:column}}
</style></head><body><main class="app-shell records-page">
<header class="topbar"><div class="brand-and-menu"><button class="menu-toggle" id="menuToggle" type="button" aria-label="Open menu" aria-expanded="false">☰</button><div><h1>JPW Khata</h1><p class="muted">All records · <?=count($records)?> transactions</p></div></div><a class="btn secondary" href="index.php">← Dashboard</a></header>
<div class="sidebar-scrim" id="sidebarScrim"></div><aside class="side-menu" id="sideMenu" aria-label="Main menu"><div class="side-menu-heading"><strong>JPW Khata</strong><button type="button" id="menuClose" class="menu-close" aria-label="Close menu">×</button></div><a href="index.php">Dashboard</a><a href="records.php">See All Records</a><a href="change-account.php">Change username and password</a><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button type="submit">Log out</button></form></aside>
<?php if($notice): ?><div class="alert success" role="status"><?=e($notice)?></div><?php endif; ?><?php if($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
<div class="records-toolbar"><div class="left"><button type="button" class="btn pdf-btn" id="openPdfModal">Download PDF</button></div><div class="right"><button type="button" class="btn delete-btn" id="deleteSelectedButton">Delete selected</button></div></div><p class="select-note">Select the checkbox beside any record you want to remove. Deletion is permanent.</p>
<form method="post" id="deleteForm"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_selected">
<?php if(!$grouped): ?><section class="panel empty-state"><strong>No records yet</strong><p>Purchases and payments will be grouped here by date.</p><a class="btn primary" href="index.php">Add your first record</a></section><?php endif; ?>
<?php $serial=0; foreach($grouped as $date=>$items): ?><section class="date-group full-date-group"><div class="date-heading"><strong><?=e(date('d M Y',strtotime($date)))?></strong><span><?=count($items)?> <?=count($items)===1?'record':'records'?></span></div><div class="table-scroll"><table class="records-table"><thead><tr><th class="selection-cell"><input type="checkbox" class="select-date" aria-label="Select all records for this date"></th><th>S.No.</th><th>Date / Time</th><th>Particular</th><th>Description</th><th class="amount-column">Amount</th></tr></thead><tbody><?php foreach($items as $r): $serial++; ?><tr><td class="selection-cell"><input type="checkbox" name="records[]" value="<?=e($r['type'].':'.$r['id'])?>" class="record-check" aria-label="Select record <?=e($serial)?>"></td><td class="serial-cell"><?=e($serial)?></td><td class="date-cell"><strong><?=e(date('d M Y',strtotime($r['created_at'])))?></strong><span><?=e(date('g:i a',strtotime($r['created_at'])))?></span></td><td class="particular-cell"><?=e(particular_records($r))?></td><td class="description-cell"><?=e(desc_records($r))?></td><td class="amount-cell <?= $r['type']==='purchase'?'red':'green' ?>"><?=e(money($r['amount']))?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endforeach; ?>
<div class="delete-bottom"><button type="button" class="btn delete-btn" id="deleteAllButton">Delete all records</button></div></form>
<footer class="footer-note"><a href="index.php">← Back to dashboard</a></footer></main>
<div class="modal-backdrop" id="confirmModal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle"><div class="modal-box"><h2 id="confirmTitle">Are you sure you want to delete?</h2><p id="confirmText">This action cannot be undone.</p><div class="modal-actions"><button class="btn delete-btn" id="confirmYes" type="button">Yes, delete</button><button class="btn secondary" id="confirmCancel" type="button">Cancel</button></div></div></div>
<div class="modal-backdrop" id="pdfModal" role="dialog" aria-modal="true" aria-labelledby="pdfTitle"><div class="modal-box"><h2 id="pdfTitle">Download transaction PDF</h2><p>Choose the period to include in your report.</p><div class="modal-options"><label><input type="radio" name="pdfRange" value="all" checked> All records</label><label><input type="radio" name="pdfRange" value="1m"> Last 1 month</label><label><input type="radio" name="pdfRange" value="3m"> Last 3 months</label><label><input type="radio" name="pdfRange" value="custom"> From / To dates</label><div class="custom-dates" id="customDates"><label>From<input type="date" id="pdfFrom"></label><label>To<input type="date" id="pdfTo"></label></div></div><div class="modal-actions"><button class="btn pdf-btn" id="downloadPdf" type="button">Download PDF</button><button class="btn secondary" id="closePdfModal" type="button">Cancel</button></div></div></div>
<script>
const csrf=<?=json_encode(csrf_token())?>; const confirmModal=document.getElementById('confirmModal'); let pendingAction='';
function openConfirm(action, message){pendingAction=action;document.getElementById('confirmText').textContent=message;confirmModal.classList.add('open')}
document.getElementById('confirmCancel').addEventListener('click',()=>confirmModal.classList.remove('open'));
document.getElementById('confirmYes').addEventListener('click',()=>{if(pendingAction==='selected'){const selected=[...document.querySelectorAll('.record-check:checked')];if(!selected.length){confirmModal.classList.remove('open');alert('Select at least one record first.');return}document.getElementById('deleteForm').submit()}else if(pendingAction==='all'){const f=document.createElement('form');f.method='post';f.action='records.php';f.innerHTML='<input name="csrf_token"><input name="action" value="delete_all"><input name="confirm_delete_all" value="YES_DELETE_ALL">';f.elements.csrf_token.value=csrf;document.body.appendChild(f);f.submit()}});
document.getElementById('deleteSelectedButton').addEventListener('click',()=>{if(!document.querySelector('.record-check:checked')){alert('Select at least one record first.');return}openConfirm('selected','The selected purchase/payment records will be permanently deleted from the database. This cannot be undone.')});
document.getElementById('deleteAllButton').addEventListener('click',()=>openConfirm('all','ALL purchase and payment records will be permanently deleted from the database. This cannot be undone. Consider downloading a backup first.'));
document.querySelectorAll('.select-date').forEach(master=>master.addEventListener('change',()=>{const table=master.closest('table');table.querySelectorAll('.record-check').forEach(cb=>cb.checked=master.checked)}));
const pdfModal=document.getElementById('pdfModal');document.getElementById('openPdfModal').addEventListener('click',()=>pdfModal.classList.add('open'));document.getElementById('closePdfModal').addEventListener('click',()=>pdfModal.classList.remove('open'));
document.querySelectorAll('input[name="pdfRange"]').forEach(r=>r.addEventListener('change',()=>document.getElementById('customDates').classList.toggle('visible',r.value==='custom'&&r.checked)));
document.getElementById('downloadPdf').addEventListener('click',()=>{const range=document.querySelector('input[name="pdfRange"]:checked').value;let url='records.php?action=pdf&range='+encodeURIComponent(range);if(range==='custom'){const from=document.getElementById('pdfFrom').value,to=document.getElementById('pdfTo').value;if(!from||!to||from>to){alert('Please choose a valid From and To date.');return}url+='&from='+encodeURIComponent(from)+'&to='+encodeURIComponent(to)}window.location.href=url;pdfModal.classList.remove('open')});
[confirmModal,pdfModal].forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
</script></body></html>