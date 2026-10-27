<?php
require_once __DIR__ . '/config/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$pdo = db(); $error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $newUsername = trim((string)($_POST['username'] ?? ''));
    try {
        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]); $user = $stmt->fetch();
        if (!$user || !password_verify($current, $user['password_hash'])) $error = 'Your current password is incorrect.';
        elseif ($newUsername !== '' && (mb_strlen($newUsername) < 3 || mb_strlen($newUsername) > 80)) $error = 'Username must be between 3 and 80 characters.';
        elseif (!preg_match('/^(?=.*\d)(?=.*[^A-Za-z0-9]).{7,}$/s', $new)) $error = 'New password must be at least 7 characters and include at least one number and one special character.';
        elseif ($new !== $confirm) $error = 'New password and confirmation do not match.';
        else {
            if ($newUsername !== '' && $newUsername !== $user['username']) {
                $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
                $check->execute([$newUsername, (int)$user['id']]);
                if ($check->fetch()) throw new RuntimeException('That username is already in use. Choose another one.');
            }
            $username = $newUsername !== '' ? $newUsername : $user['username'];
            $update = $pdo->prepare('UPDATE users SET username = ?, password_hash = ? WHERE id = ?');
            $update->execute([$username, password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
            $_SESSION['username'] = $username;
            session_regenerate_id(true);
            $success = 'Username and password updated successfully.';
        }
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
      catch (Throwable $ex) { $error = 'Could not update account. Please check that the username is available and try again.'; }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Change username and password · JPW Khata</title><link rel="stylesheet" href="assets/css/style.css"></head><body class="auth-page"><main class="auth-card account-card"><a class="btn secondary back-link" href="index.php">← Back to dashboard</a><h1>Change username and password</h1><br><?php if($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?><?php if($success): ?><div class="alert success" role="status"><?=e($success)?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>Change username <span class="optional">(optional)</span><input name="username" minlength="3" maxlength="80" autocomplete="username" value="<?=e($_SESSION['username'] ?? '')?>"></label><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><label>New password<input type="password" name="new_password" required minlength="7" autocomplete="new-password"><small>At least 7 characters, including at least 1 number and 1 special character.</small></label><label>Confirm new password<input type="password" name="confirm_password" required minlength="7" autocomplete="new-password"></label><button class="btn primary full" type="submit">Save changes</button></form></main></body></html>