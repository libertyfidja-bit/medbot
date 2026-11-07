<?php
/**
 * MedBot gate — PHP runs this file before EVERY page (auto_prepend_file in the Dockerfile).
 * It checks that the visitor is signed in and that their role may open the page.
 * The rules are in auth.php (function auth_allowed).
 */
require_once __DIR__ . '/auth.php';
auth_gate();
