<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

http_response_code((int) $status);
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
