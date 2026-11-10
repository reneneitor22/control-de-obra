<?php
/**
 * Punto de entrada: con sesión va al dashboard, sin sesión al login.
 * No hay dashboard de "obra" aquí — Finanzas ya ES el dashboard.
 */
require __DIR__ . '/../auth.php';

redirect(current_user() !== null ? 'finanzas.php' : 'login.php');
