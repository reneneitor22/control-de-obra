<?php
require __DIR__ . '/../auth.php';
logout();
redirect('login.php');
