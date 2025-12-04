<?php
/* 修复无法播放问题 */
error_reporting(0);

$n = [
    'sdws' => "24581", //山东卫视*
    'sdql' => "24584", //山东齐鲁
    'sdxw' => "24602", //山东新闻*
    'sdty' => "24587", //山东体育休闲
    'sdsh' => "24596", //山东生活
    'sdzy' => "24593", //山东综艺
    'sdzy' => "24593", //山东综艺
    'sdnk' => "24599", //山东农科
    'sdwl' => "24590", //山东文旅
    'sdse' => "24605", //山东少儿*
];

$id = $_GET['id'] ?? 'sdws';

// 设置CORS头
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Expose-Headers: Content-Length, Content-Range");
header("Access-Control-Max-Age: 86400");

// 预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 调试模式
if (isset($_GET['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    echo "<pre>";
    
    // 测试直接获取M3U8内容
    $liveUrl = getLiveUrl($id, $n);
    echo "直播源地址: " . $liveUrl . "\n\n";
    
    if ($liveUrl) {
        $content = getM3U8Content($liveUrl);
        echo "原始M3U8内容（前500字符）:\n";
        echo substr($content, 0, 500) . "\n\n";
        
        echo "M3U8分析:\n";
        analyzeM3U8($content);
    }
    
    exit();
}

// 检查是否请求M3U8
if (isset($_GET['type']) && $_GET['type'] === 'm3u8') {
    proxyStream($id, $n);
    exit();
}

// 正常请求返回JSON
$liveUrl = getLiveUrl($id, $n);

if ($liveUrl) {
    header('Content-Type: application/json');
    echo json_encode([
        'code' => 200,
        'url' => $liveUrl,
        'm3u8_url' => 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?id=' . $id . '&type=m3u8',
        'message' => 'success',
        'py' => 'zhibo.jishuwo.com'
    ]);
} else {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'code' => 500,
        'message' => '获取直播流失败'
    ]);
}

// 流代理函数 - 修复版
function proxyStream($id, $channelList) {
    $liveUrl = getLiveUrl($id, $channelList);
    
    if (!$liveUrl) {
        returnErrorM3U8('无法获取直播源地址');
        exit();
    }
    
    // 获取M3U8内容
    $m3u8Content = getM3U8Content($liveUrl);
    
    if (!$m3u8Content) {
        returnErrorM3U8('无法获取M3U8内容');
        exit();
    }
    
    // 设置响应头
    setStreamHeaders();
    
    // 处理M3U8内容
    $processedContent = processM3U8($m3u8Content, $liveUrl);
    
    // 输出处理后的内容
    echo $processedContent;
}

// 获取M3U8内容
function getM3U8Content($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Referer: https://v.iqilu.com/',
            'Origin: https://v.iqilu.com',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: */*',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Accept-Encoding: gzip'
        ],
        CURLOPT_ENCODING => 'gzip'
    ]);
    
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log("获取M3U8失败: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    if ($httpCode != 200) {
        error_log("HTTP状态码错误: " . $httpCode);
        return false;
    }
    
    return $content;
}

// 处理M3U8内容（关键修复）
function processM3U8($content, $baseUrl) {
    // 提取主M3U8的URL部分用于构建绝对路径
    $basePath = dirname($baseUrl);
    
    // 分析内容类型
    if (strpos($content, '#EXT-X-STREAM-INF') !== false) {
        // 这是主M3U8，包含多个码率
        return processMasterM3U8($content, $basePath, $baseUrl);
    } else {
        // 这是播放列表M3U8，包含TS片段
        return processPlaylistM3U8($content, $basePath, $baseUrl);
    }
}

// 处理主M3U8（多码率）
function processMasterM3U8($content, $basePath, $baseUrl) {
    $lines = explode("\n", $content);
    $processed = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if (empty($line)) continue;
        
        // 处理带宽信息行
        if (strpos($line, '#EXT-X-STREAM-INF') !== false) {
            $processed[] = $line;
        }
        // 处理子M3U8 URL
        elseif (!preg_match('/^#/', $line) && !empty($line)) {
            // 转换相对路径为绝对路径
            $url = convertToAbsoluteUrl($line, $basePath);
            
            // 替换为我们的代理地址
            $channelId = $_GET['id'] ?? 'sdws';
            $proxyUrl = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?id=' . $channelId . '&sub_m3u8=' . urlencode($url);
            
            $processed[] = $proxyUrl;
        } else {
            $processed[] = $line;
        }
    }
    
    return implode("\n", $processed);
}

// 处理播放列表M3U8（TS片段）
function processPlaylistM3U8($content, $basePath, $baseUrl) {
    $lines = explode("\n", $content);
    $processed = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if (empty($line)) continue;
        
        // 保持注释行不变
        if (preg_match('/^#/', $line)) {
            $processed[] = $line;
        }
        // 处理TS片段URL
        elseif (!empty($line)) {
            // 如果是完整的URL，直接使用
            if (filter_var($line, FILTER_VALIDATE_URL)) {
                $tsUrl = $line;
            } else {
                // 转换相对路径为绝对路径
                $tsUrl = convertToAbsoluteUrl($line, $basePath);
            }
            
            // 通过我们的代理访问TS片段
            $proxyTsUrl = getTsProxyUrl($tsUrl);
            
            $processed[] = $proxyTsUrl;
        }
    }
    
    return implode("\n", $processed);
}

// TS片段代理URL生成
function getTsProxyUrl($tsUrl) {
    $channelId = $_GET['id'] ?? 'sdws';
    return 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?id=' . $channelId . '&ts=' . urlencode(base64_encode($tsUrl));
}

// 转换相对URL为绝对URL
function convertToAbsoluteUrl($url, $basePath) {
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }
    
    // 如果是绝对路径
    if (strpos($url, '/') === 0) {
        // 提取协议和域名
        $parts = parse_url($basePath);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        
        if ($host) {
            return $scheme . '://' . $host . $url;
        }
    }
    
    // 否则作为相对路径处理
    return rtrim($basePath, '/') . '/' . ltrim($url, '/');
}

// 设置流媒体头
function setStreamHeaders() {
    if (ob_get_level()) ob_end_clean();
    
    // 检查是否是TS片段请求
    if (isset($_GET['ts'])) {
        header('Content-Type: video/MP2T');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=3600');
        header('Accept-Ranges: bytes');
        return;
    }
    
    // M3U8请求
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// 返回错误M3U8
function returnErrorM3U8($message) {
    header('Content-Type: application/vnd.apple.mpegurl');
    header('HTTP/1.1 500 Internal Server Error');
    
    $errorM3U8 = "#EXTM3U\n";
    $errorM3U8 .= "#EXT-X-VERSION:3\n";
    $errorM3U8 .= "#EXT-X-MEDIA-SEQUENCE:0\n";
    $errorM3U8 .= "#EXT-X-TARGETDURATION:10\n";
    $errorM3U8 .= "#EXTINF:10.0,\n";
    $errorM3U8 .= "#EXT-X-ENDLIST\n";
    $errorM3U8 .= "# " . $message . "\n";
    
    echo $errorM3U8;
}

// TS片段代理处理
if (isset($_GET['ts'])) {
    $tsUrl = base64_decode($_GET['ts']);
    if ($tsUrl && filter_var($tsUrl, FILTER_VALIDATE_URL)) {
        proxyTsSegment($tsUrl);
        exit();
    }
}

// 子M3U8代理处理
if (isset($_GET['sub_m3u8'])) {
    $subM3U8Url = $_GET['sub_m3u8'];
    if ($subM3U8Url && filter_var($subM3U8Url, FILTER_VALIDATE_URL)) {
        $content = getM3U8Content($subM3U8Url);
        if ($content) {
            setStreamHeaders();
            $processed = processPlaylistM3U8($content, dirname($subM3U8Url), $subM3U8Url);
            echo $processed;
        } else {
            returnErrorM3U8('无法获取子M3U8');
        }
        exit();
    }
}

// TS片段代理函数
function proxyTsSegment($tsUrl) {
    setStreamHeaders();
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $tsUrl,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Referer: https://v.iqilu.com/',
            'Origin: https://v.iqilu.com',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: */*',
            'Accept-Encoding: identity'
        ],
        CURLOPT_WRITEFUNCTION => function($ch, $data) {
            echo $data;
            return strlen($data);
        }
    ]);
    
    curl_exec($ch);
    curl_close($ch);
}

// 获取直播URL函数（根据之前的工作版本）
function getLiveUrl($id, $channelList) {
    $salt = getsalt();
    if (!$salt) return false;
    
    $key = getkey();
    if (!$key) return false;
    
    $t = getMillisecond();
    $s = md5($channelList[$id] . $t . $salt);
    
    $uri = "https://feiying.litenews.cn/api/v1/auth/exchange?t=$t&s=$s";
    $data = base64_encode(aesencrypt('{"channelMark":"' . $channelList[$id] . '"}', $key));
    
    $str = post($uri, $data);
    if (!$str) return false;
    
    $decrypted = aesdecrypt($str, $key);
    if (!$decrypted) return false;
    
    $result = json_decode($decrypted, true);
    
    if (isset($result['data']) && $result['data']) {
        return $result['data'];
    }
    
    return false;
}

// 其他辅助函数（getsalt, getkey, getMillisecond, post, aesencrypt, aesdecrypt, get）
// 保持与之前相同的实现，确保能正确获取直播源

function getsalt() {
    $d = get("https://v.iqilu.com/live/sdtv/");
    if ($d) {
        preg_match("/mxpx = '(.*?)'/", $d, $matches);
        if (!empty($matches[1])) {
            return $matches[1];
        }
    }
    return false;
}

function getkey() {
    $d = get("https://v.iqilu.com/live/sdtv/");
    if ($d) {
        preg_match("/aly = '(.*?)'/", $d, $matches);
        if (!empty($matches[1])) {
            return $matches[1];
        }
    }
    return false;
}

function getMillisecond() {
    list($t1, $t2) = explode(' ', microtime());
    return (float)sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000);
}

function post($url, $data) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: text/plain',
            'Referer: https://v.iqilu.com/',
            'Origin: https://v.iqilu.com',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ],
        CURLOPT_TIMEOUT => 10
    ]);
    
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function aesencrypt($str, $key) {
    $cipher = "AES-128-CBC";
    $iv = "0000000000000000";
    $key = substr(str_pad($key, 16, '0'), 0, 16);
    return openssl_encrypt($str, $cipher, $key, OPENSSL_RAW_DATA, $iv);
}

function aesdecrypt($str, $key) {
    $cipher = "AES-128-CBC";
    $iv = "0000000000000000";
    $key = substr(str_pad($key, 16, '0'), 0, 16);
    $data = base64_decode($str);
    if (!$data) return false;
    return openssl_decrypt($data, $cipher, $key, OPENSSL_RAW_DATA, $iv);
}

function get($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_REFERER => 'https://v.iqilu.com/',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

// M3U8分析函数（调试用）
function analyzeM3U8($content) {
    $lines = explode("\n", $content);
    $info = [
        'type' => 'unknown',
        'version' => null,
        'target_duration' => null,
        'media_sequence' => null,
        'stream_count' => 0,
        'ts_count' => 0,
        'has_endlist' => false
    ];
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if (strpos($line, '#EXT-X-VERSION:') === 0) {
            $info['version'] = str_replace('#EXT-X-VERSION:', '', $line);
        }
        elseif (strpos($line, '#EXT-X-TARGETDURATION:') === 0) {
            $info['target_duration'] = str_replace('#EXT-X-TARGETDURATION:', '', $line);
        }
        elseif (strpos($line, '#EXT-X-MEDIA-SEQUENCE:') === 0) {
            $info['media_sequence'] = str_replace('#EXT-X-MEDIA-SEQUENCE:', '', $line);
        }
        elseif (strpos($line, '#EXT-X-STREAM-INF') === 0) {
            $info['type'] = 'master';
            $info['stream_count']++;
        }
        elseif (strpos($line, '#EXTINF') === 0) {
            $info['type'] = 'playlist';
            $info['ts_count']++;
        }
        elseif (strpos($line, '#EXT-X-ENDLIST') === 0) {
            $info['has_endlist'] = true;
        }
    }
    
    echo "M3U8类型: " . $info['type'] . "\n";
    echo "版本: " . $info['version'] . "\n";
    echo "目标时长: " . $info['target_duration'] . "\n";
    if ($info['type'] == 'master') {
        echo "码率数量: " . $info['stream_count'] . "\n";
    } else {
        echo "TS片段数量: " . $info['ts_count'] . "\n";
        echo "是否结束列表: " . ($info['has_endlist'] ? '是' : '否') . "\n";
    }
}
?>