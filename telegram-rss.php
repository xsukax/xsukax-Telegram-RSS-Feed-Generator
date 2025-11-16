<?php
/**
 * xsukax Telegram RSS Feed Generator
 * Self-contained RSS feed generator for Telegram public channels
 * No external dependencies - scrapes Telegram's public web interface
 */

// Configuration
define('POSTS_PER_FEED', 50);
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// Check if requesting RSS feed
if (isset($_GET['feed']) && !empty($_GET['feed'])) {
    $channel = sanitizeChannelName($_GET['feed']);
    if ($channel) {
        generateRSSFeed($channel);
    } else {
        http_response_code(400);
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Error</title><description>Invalid channel name</description></channel></rss>';
    }
    exit;
}

// Initialize variables
$channel_name = '';
$preview_url = '';
$error = '';

// Process form submission
if (isset($_GET['channel']) && !empty(trim($_GET['channel']))) {
    $input = trim($_GET['channel']);
    $channel_name = extractChannelName($input);
    
    if ($channel_name) {
        if (preg_match('/^[a-zA-Z0-9_]{5,32}$/', $channel_name)) {
            $preview_url = getCurrentUrl() . '?feed=' . urlencode($channel_name);
        } else {
            $error = 'Invalid channel name format. Must be 5-32 characters (letters, numbers, underscores only).';
        }
    } else {
        $error = 'Could not extract channel name from input.';
    }
}

// AJAX handler
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    if ($error) {
        echo json_encode(['success' => false, 'error' => $error]);
    } else if ($preview_url) {
        echo json_encode(['success' => true, 'rss_url' => $preview_url, 'channel_name' => $channel_name]);
    }
    exit;
}

/**
 * Get current URL
 */
function getCurrentUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    return $protocol . '://' . $host . $script;
}

/**
 * Extract channel name from various formats
 */
function extractChannelName($input) {
    $input = trim($input);
    if (preg_match('#^https?://t\.me/s/([a-zA-Z0-9_]+)#i', $input, $matches)) return $matches[1];
    if (preg_match('#^https?://t\.me/([a-zA-Z0-9_]+)#i', $input, $matches)) return $matches[1];
    if (preg_match('#^t\.me/s/([a-zA-Z0-9_]+)#i', $input, $matches)) return $matches[1];
    if (preg_match('#^t\.me/([a-zA-Z0-9_]+)#i', $input, $matches)) return $matches[1];
    if (preg_match('/^@([a-zA-Z0-9_]+)$/', $input, $matches)) return $matches[1];
    if (preg_match('/^[a-zA-Z0-9_]+$/', $input)) return $input;
    return false;
}

/**
 * Sanitize channel name
 */
function sanitizeChannelName($name) {
    $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    return (strlen($name) >= 5 && strlen($name) <= 32) ? $name : false;
}

/**
 * Fetch Telegram channel data
 */
function fetchChannelData($channel) {
    $url = "https://t.me/s/" . $channel;
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: " . USER_AGENT . "\r\n",
            'timeout' => 15,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);
    
    $html = @file_get_contents($url, false, $context);
    return $html !== false ? $html : null;
}

/**
 * Parse posts from HTML
 */
function parsePosts($html, $channel) {
    $posts = [];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $messages = $xpath->query("//div[contains(@class, 'tgme_widget_message')]");
    
    $count = 0;
    foreach ($messages as $message) {
        if ($count >= POSTS_PER_FEED) break;
        
        $post = [];
        
        // Get post ID and link
        $dataPost = $message->getAttribute('data-post');
        if (!$dataPost) continue;
        
        $parts = explode('/', $dataPost);
        $postId = end($parts);
        $post['id'] = $postId;
        $post['link'] = "https://t.me/" . $channel . "/" . $postId;
        
        // Get timestamp
        $timeNodes = $xpath->query(".//time[@datetime]", $message);
        if ($timeNodes->length > 0) {
            $datetime = $timeNodes->item(0)->getAttribute('datetime');
            $timestamp = strtotime($datetime);
            $post['date'] = date('r', $timestamp);
            $post['timestamp'] = $timestamp;
        } else {
            $post['date'] = date('r');
            $post['timestamp'] = time();
        }
        
        // Get text content
        $text = '';
        $textNodes = $xpath->query(".//div[contains(@class, 'tgme_widget_message_text')]", $message);
        if ($textNodes->length > 0) {
            $textNode = $textNodes->item(0);
            $text = getInnerHTML($textNode);
            $text = str_replace(['<br>', '<br/>', '<br />'], "\n", $text);
            $text = strip_tags($text, '<a><b><i><strong><em><u><s>');
            $text = trim($text);
        }
        
        // Create title from text
        $titleText = strip_tags($text);
        $titleText = preg_replace('/\s+/', ' ', $titleText);
        $post['title'] = mb_substr($titleText, 0, 100) ?: "Post #" . $postId;
        if (mb_strlen($titleText) > 100) {
            $post['title'] .= '...';
        }
        
        // Create description
        $description = '';
        if ($text) {
            $textFormatted = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
            $description = '<p>' . $textFormatted . '</p>';
        } else {
            $description = "View post on Telegram";
        }
        
        $post['description'] = $description;
        
        // Only add if we have content
        if (!empty($post['title']) || !empty($description)) {
            $posts[] = $post;
            $count++;
        }
    }
    
    return $posts;
}

/**
 * Get inner HTML of a node
 */
function getInnerHTML($node) {
    $innerHTML = '';
    $children = $node->childNodes;
    foreach ($children as $child) {
        $innerHTML .= $node->ownerDocument->saveHTML($child);
    }
    return $innerHTML;
}

/**
 * Generate RSS 2.0 feed
 */
function generateRSSFeed($channel) {
    header('Content-Type: application/xml; charset=utf-8');
    
    $html = fetchChannelData($channel);
    
    if (!$html) {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<rss version="2.0"><channel><title>Error</title><description>Could not fetch channel data. Channel may not exist or is private.</description></channel></rss>';
        return;
    }
    
    // Use channel name as title
    $channelTitle = '@' . $channel;
    
    // Get channel description
    $channelDesc = "Telegram channel: @" . $channel;
    if (preg_match('/<div class="tgme_channel_info_description"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
        $desc = strip_tags($matches[1]);
        $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $channelDesc = trim($desc);
    }
    
    $posts = parsePosts($html, $channel);
    
    // Generate RSS XML
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
    echo '<channel>' . "\n";
    echo '<title>' . htmlspecialchars($channelTitle, ENT_XML1, 'UTF-8') . '</title>' . "\n";
    echo '<link>https://t.me/s/' . htmlspecialchars($channel, ENT_XML1, 'UTF-8') . '</link>' . "\n";
    echo '<description>' . htmlspecialchars($channelDesc, ENT_XML1, 'UTF-8') . '</description>' . "\n";
    echo '<language>en</language>' . "\n";
    echo '<lastBuildDate>' . date('r') . '</lastBuildDate>' . "\n";
    echo '<pubDate>' . date('r') . '</pubDate>' . "\n";
    echo '<atom:link href="' . htmlspecialchars(getCurrentUrl() . '?feed=' . $channel, ENT_XML1, 'UTF-8') . '" rel="self" type="application/rss+xml" />' . "\n";
    echo '<generator>xsukax Telegram RSS Feed Generator</generator>' . "\n";
    
    foreach ($posts as $post) {
        echo '<item>' . "\n";
        echo '<title>' . htmlspecialchars($post['title'], ENT_XML1, 'UTF-8') . '</title>' . "\n";
        echo '<link>' . htmlspecialchars($post['link'], ENT_XML1, 'UTF-8') . '</link>' . "\n";
        echo '<guid isPermaLink="true">' . htmlspecialchars($post['link'], ENT_XML1, 'UTF-8') . '</guid>' . "\n";
        echo '<pubDate>' . $post['date'] . '</pubDate>' . "\n";
        echo '<description><![CDATA[' . $post['description'] . ']]></description>' . "\n";
        echo '</item>' . "\n";
    }
    
    echo '</channel>' . "\n";
    echo '</rss>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="xsukax Telegram RSS Feed Generator - Self-hosted solution for Telegram channels">
    <title>xsukax Telegram RSS Feed Generator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans",Helvetica,Arial,sans-serif}.slide-in{animation:slideIn 0.3s ease-out}.slide-out{animation:slideOut 0.3s ease-out}@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}@keyframes slideOut{from{transform:translateX(0);opacity:1}to{transform:translateX(100%);opacity:0}}.pulse{animation:pulse 1.5s ease-in-out infinite}@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.5}}</style>
</head>
<body class="bg-gray-50 min-h-screen">
    
    <div id="notificationContainer" class="fixed top-4 right-4 z-50 flex flex-col gap-2 max-w-md w-full px-4"></div>
    
    <main class="container mx-auto px-4 py-8 max-w-4xl">
        
        <header class="mb-12">
            <h1 class="text-4xl font-semibold text-gray-900 mb-3">xsukax Telegram RSS Feed Generator</h1>
            <p class="text-lg text-gray-600">Generate RSS feeds from public Telegram channels - Self-hosted, no external dependencies</p>
        </header>
        
        <section class="bg-white border border-gray-300 rounded-md p-6 mb-6">
            
            <form id="channelForm" method="get" class="mb-6">
                <div class="mb-4">
                    <label for="channelInput" class="block text-sm font-semibold text-gray-900 mb-2">Telegram Channel</label>
                    <input type="text" id="channelInput" name="channel" placeholder="channelname, @channelname, or t.me/channelname" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono text-sm" value="<?php echo htmlspecialchars($channel_name, ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="off">
                    <p class="mt-2 text-xs text-gray-600">Supports: channelname, @channelname, t.me/channelname, https://t.me/channelname</p>
                    <p class="mt-1 text-xs text-yellow-700">⚠ Only public channels are supported</p>
                </div>
                
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-md transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">Generate RSS Feed</button>
            </form>
            
            <div id="resultSection" class="<?php echo $preview_url || $error ? '' : 'hidden'; ?>">
                <div id="successResult" class="<?php echo $preview_url ? '' : 'hidden'; ?>">
                    <h2 class="text-xl font-semibold text-gray-900 mb-3">Your RSS Feed URL</h2>
                    <div class="bg-green-50 border border-green-300 rounded-md p-4 mb-4">
                        <div class="flex items-start gap-3">
                            <code id="rssUrl" class="flex-1 text-sm text-gray-800 break-all font-mono bg-white px-2 py-1 rounded border border-green-200"><?php echo htmlspecialchars($preview_url, ENT_QUOTES, 'UTF-8'); ?></code>
                            <button onclick="copyToClipboard()" class="flex-shrink-0 bg-gray-700 hover:bg-gray-800 text-white px-3 py-1 rounded text-sm font-medium transition-colors duration-150">Copy</button>
                        </div>
                    </div>
                    <div class="bg-blue-50 border border-blue-300 rounded-md p-4 mb-4">
                        <p class="text-sm text-gray-900 mb-1"><strong>How to use:</strong> Copy the RSS URL above and add it to your RSS reader (Feedly, Inoreader, etc.)</p>
                        <p class="text-sm text-gray-700">The feed updates automatically when your RSS reader requests it.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a id="rssLink" href="<?php echo htmlspecialchars($preview_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-md transition-colors duration-150">View Feed</a>
                        <button onclick="resetForm()" class="bg-gray-500 hover:bg-gray-600 text-white font-semibold py-2 px-4 rounded-md transition-colors duration-150">New Feed</button>
                    </div>
                </div>
                
                <div id="errorResult" class="<?php echo $error ? '' : 'hidden'; ?>">
                    <div class="bg-red-50 border border-red-300 rounded-md p-4">
                        <h2 class="text-lg font-semibold text-red-800 mb-2">Error</h2>
                        <p id="errorMessage" class="text-red-700 mb-3 text-sm"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
                        <button onclick="resetForm()" class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-md transition-colors duration-150">Try Again</button>
                    </div>
                </div>
            </div>
            
        </section>
        
    </main>
    
    <footer class="text-center py-8 text-gray-600 border-t border-gray-200 mt-12">
        <p class="text-sm font-medium">xsukax Telegram RSS Feed Generator</p>
        <p class="text-xs mt-1 text-gray-500">Self-hosted • No External APIs • Open Source</p>
    </footer>

    <script>
        'use strict';
        
        const NotificationManager = {
            container: null,
            init() { this.container = document.getElementById('notificationContainer'); },
            show(message, type = 'success', duration = 4000) {
                const notification = document.createElement('div');
                const colors = {
                    success: 'bg-green-600 border-green-700',
                    error: 'bg-red-600 border-red-700',
                    info: 'bg-blue-600 border-blue-700',
                    warning: 'bg-yellow-600 border-yellow-700'
                };
                notification.className = `slide-in ${colors[type] || colors.success} text-white px-4 py-3 rounded-md border shadow-lg flex items-center justify-between gap-3`;
                notification.innerHTML = `<span class="flex-1 text-sm font-medium">${this.escapeHtml(message)}</span><button onclick="NotificationManager.close(this.parentElement)" class="flex-shrink-0 text-white hover:text-gray-200 font-bold text-xl leading-none">&times;</button>`;
                this.container.appendChild(notification);
                if (duration > 0) setTimeout(() => this.close(notification), duration);
            },
            close(element) {
                element.classList.remove('slide-in');
                element.classList.add('slide-out');
                setTimeout(() => element.remove(), 300);
            },
            escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        };
        
        NotificationManager.init();
        
        document.getElementById('channelForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const input = document.getElementById('channelInput').value.trim();
            if (!input) {
                NotificationManager.show('Please enter a channel name or URL', 'error');
                return;
            }
            
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.innerHTML = '<span class="pulse">Generating...</span>';
            submitBtn.disabled = true;
            
            try {
                const response = await fetch(`?channel=${encodeURIComponent(input)}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await response.json();
                
                if (data.success) {
                    displaySuccess(data.rss_url, data.channel_name);
                    NotificationManager.show('RSS feed URL generated successfully!', 'success');
                } else {
                    displayError(data.error);
                    NotificationManager.show(data.error, 'error');
                }
            } catch (error) {
                displayError('Failed to generate feed. Please try again.');
                NotificationManager.show('Failed to generate feed. Please try again.', 'error');
            } finally {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            }
        });
        
        function displaySuccess(rssUrl, channelName) {
            document.getElementById('rssUrl').textContent = rssUrl;
            document.getElementById('rssLink').href = rssUrl;
            document.getElementById('channelInput').value = channelName;
            document.getElementById('resultSection').classList.remove('hidden');
            document.getElementById('successResult').classList.remove('hidden');
            document.getElementById('errorResult').classList.add('hidden');
            document.getElementById('resultSection').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        function displayError(message) {
            document.getElementById('errorMessage').textContent = message;
            document.getElementById('resultSection').classList.remove('hidden');
            document.getElementById('errorResult').classList.remove('hidden');
            document.getElementById('successResult').classList.add('hidden');
            document.getElementById('resultSection').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        async function copyToClipboard() {
            const rssUrl = document.getElementById('rssUrl').textContent;
            try {
                await navigator.clipboard.writeText(rssUrl);
                NotificationManager.show('RSS URL copied to clipboard!', 'success', 3000);
            } catch (error) {
                const textarea = document.createElement('textarea');
                textarea.value = rssUrl;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand('copy');
                    NotificationManager.show('RSS URL copied to clipboard!', 'success', 3000);
                } catch (e) {
                    NotificationManager.show('Failed to copy. Please select and copy manually.', 'error');
                }
                document.body.removeChild(textarea);
            }
        }
        
        function resetForm() {
            document.getElementById('channelInput').value = '';
            document.getElementById('resultSection').classList.add('hidden');
            document.getElementById('channelInput').focus();
        }
        
        window.addEventListener('DOMContentLoaded', () => {
            <?php if ($preview_url): ?>
            NotificationManager.show('RSS feed generated successfully!', 'success');
            <?php elseif ($error): ?>
            NotificationManager.show('<?php echo addslashes($error); ?>', 'error');
            <?php endif; ?>
        });
    </script>
    
</body>
</html>