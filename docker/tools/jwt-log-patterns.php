<?php

declare(strict_types=1);

// Both the actual raw-log rejection and evidence redaction use these patterns.
return [
    '/jwt-canary-[a-f0-9]{40}/i',
    '/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/',
    '/-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----.*?(?:-----END (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----|\z)/s',
];
