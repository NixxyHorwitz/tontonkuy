<?php
$file = 'c:\laragon\www\tonton\user\livechat.php';
$content = file_get_contents($file);

$search = <<<JS
  const senderLabels = { ai: '🤖 AI', admin: '👨‍💼 Admin', user: '🙋 Kamu', system: '' };
  const senderClass  = { ai: 'sender-ai', admin: 'sender-admin', user: 'sender-user', system: '' };

  const timeStr = time ? new Date(time).toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'}) : '';
  const label   = sender !== 'system' && sender !== 'user' ? `<div class="bubble-sender-tag \${senderClass[sender]||''} ">\${senderLabels[sender]||sender}</div>` : '';
  // AI dan admin pakai markdown, user dan system pakai plain text
  const bodyHtml = (sender === 'ai' || sender === 'admin') ? renderMarkdown(text) : (sender === 'system' ? escHtml(text) : escHtml(text) + '');
JS;

$replace = <<<JS
  const senderLabels = { ai: '🤖 AI', admin: '👨‍💼 Admin', user: '🙋 Kamu', system: '' };
  const senderClass  = { ai: 'sender-ai', admin: 'sender-admin', user: 'sender-user', system: '' };

  let labelName = senderLabels[sender] || sender;
  let actualText = text;

  if (sender === 'admin') {
      const match = text.match(/^\[(.*?)\]\s*(.*)$/is);
      if (match) {
          labelName = `👨‍💼 \${match[1]}`;
          actualText = match[2];
      }
  }

  const timeStr = time ? new Date(time).toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'}) : '';
  const label   = sender !== 'system' && sender !== 'user' ? `<div class="bubble-sender-tag \${senderClass[sender]||''} ">\${escHtml(labelName)}</div>` : '';
  // AI dan admin pakai markdown, user dan system pakai plain text
  const bodyHtml = (sender === 'ai' || sender === 'admin') ? renderMarkdown(actualText) : (sender === 'system' ? escHtml(actualText) : escHtml(actualText) + '');
JS;

// the actual string in user/livechat.php uses string interpolation ${} which PHP variable interpolation might break if not escaped. 
// However, the search string must EXACTLY match the file's content.
// Wait, the template literals in my script use \$ but I used <<<JS which doesn't evaluate \$! Wait, <<<JS does evaluate \$ if it's not <<<'JS'.
