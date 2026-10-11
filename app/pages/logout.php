<?php
declare(strict_types=1);

if (is_post()) {
    csrf_check();
    audit('deconnexion', 'user', user()['id'] ?? null);
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
}
redirect(url('login'));
