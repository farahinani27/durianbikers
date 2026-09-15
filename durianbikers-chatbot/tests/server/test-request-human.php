<?php
// Run: wp eval-file durianbikers-chatbot/tests/server/test-request-human.php --path=<docroot>
require __DIR__ . '/../lib/assert.php';
$S = 'DB_Chat_Conversation_Store';
$c = $S::ensure('', ['lang'=>'en','page_url'=>'rh-test']);
$id = (int) $c['id'];
$ep = db_chat()->endpoint();
$res = $ep->raise_human_handoff($id);
t_ok(!empty($res['handoff']), 'returns handoff flag');
t_ok(is_string($res['message']) && $res['message'] !== '', 'returns next-step text');
t_eq($S::get($id)['status'], $S::STATUS_WAITING, 'conversation set to waiting (handoff enabled)');
$S::delete_conversation($id);
t_done();
