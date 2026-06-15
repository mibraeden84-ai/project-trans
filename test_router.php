<?php
file_put_contents('C:\Users\pc\Documents\Codex\2026-05-22\router_debug.log', date('H:i:s') . " URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n", FILE_APPEND);
echo "ROUTER CALLED: " . ($_SERVER['REQUEST_URI'] ?? 'N/A');
return true;
