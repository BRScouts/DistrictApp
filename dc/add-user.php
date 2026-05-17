<?php
require_once __DIR__ . '/auth.php';
http_response_code(410);
echo 'This setup endpoint has been removed. Add people through the Leader Tool onboarding/group management flow.';
