<?php
$footerLogin = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'login.php';
?>
<footer style="
    <?= $footerLogin ? 'position:fixed;left:0;bottom:0;width:100%;box-sizing:border-box;' : '' ?>
    margin-top:30px;
    padding:18px 12px;
    border-top:1px solid #1e293b;
    color:#64748b;
    font-family:Arial,sans-serif;
    font-size:12px;
    text-align:center;
">
    Copyright 2026 by Trevizam Network | Desenvolvido e mantido por Trevizam Network
</footer>
