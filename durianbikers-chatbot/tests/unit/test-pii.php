<?php
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../../includes/class-pii.php';

t_eq(DB_Chat_PII::scrub('my card is 4111 1111 1111 1111 ok'), 'my card is [redacted] ok', 'scrub spaced card');
t_eq(DB_Chat_PII::scrub('4111-1111-1111-1111'), '[redacted]', 'scrub dashed card');
t_eq(DB_Chat_PII::scrub('the C10 is RM469 in size L'), 'the C10 is RM469 in size L', 'keep price/qty');
t_eq(DB_Chat_PII::scrub('model MT/1308C62A5106/L'), 'model MT/1308C62A5106/L', 'keep model code');
t_eq(DB_Chat_PII::scrub('cardno4111111111111111 please'), 'cardno[redacted] please', 'redact card glued to a word');
t_eq(DB_Chat_PII::scrub('MT1308123456789012A'), 'MT1308123456789012A', 'keep code with trailing letter');
t_done();
