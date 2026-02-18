<?php
/**
 * YouTubeAuthManager — 修正版（CLI＋ブラウザ両対応）
 * - CLI：すべてのチャンネルを一括認証
 * - Web：特定チャンネルの認証をブラウザで行う
 */

class YouTubeAuthManager
{
    private string $actorsFile;
    private string $clientSecretFile;
    private string $outputDir;
    private array $scopes = [
        'https://www.googleapis.com/auth/youtube.readonly',
        'https://www.googleapis.com/auth/yt-analytics.readonly'
    ];

    public function __construct(string $actorsFile, string $clientSecretFile, string $outputDir = 'oauth')
    {
        $this->actorsFile = $actorsFile;
        $this->clientSecretFile = $clientSecretFile;
        $this->outputDir = $outputDir;
    }

    /** チャンネル一覧をロード */
    private function loadChannels(): array
    {
        if (!file_exists($this->actorsFile)) return [];
        $json = json_decode(file_get_contents($this->actorsFile), true);
        return $json['items'] ?? [];
    }

    /** フォルダ作成（安全な名前） */
    private function sanitizeName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $name);
    }

    private function createChannelFolder(string $channelName): string
    {
        $safe = $this->sanitizeName($channelName);
        $folder = "{$this->outputDir}/{$safe}";
        if (!file_exists($folder)) mkdir($folder, 0777, true);
        return $folder;
    }

    /** 新規認証URL作成（ブラウザ用） */
    public function createAuthUrl(string $channelName): string
    {
        $clientSecret = json_decode(file_get_contents($this->clientSecretFile), true);
        $clientId = $clientSecret['installed']['client_id'];
        $redirectUri = $clientSecret['installed']['redirect_uris'][0];
        $scope = urlencode(implode(' ', $this->scopes));

        return sprintf(
            'https://accounts.google.com/o/oauth2/v2/auth?response_type=code&access_type=offline&client_id=%s&redirect_uri=%s&scope=%s&prompt=consent&state=%s',
            urlencode($clientId),
            urlencode($redirectUri),
            $scope,
            urlencode($channelName)
        );
    }

    /** ブラウザコールバック処理 */
    public function handleAuthCallback(string $authCode, string $channelName): void
    {
        $clientSecret = json_decode(file_get_contents($this->clientSecretFile), true);
        $clientId = $clientSecret['installed']['client_id'];
        $clientSecretKey = $clientSecret['installed']['client_secret'];
        $redirectUri = $clientSecret['installed']['redirect_uris'][0];

        $token = $this->fetchAccessToken($clientId, $clientSecretKey, $authCode, $redirectUri);
        if (isset($token['access_token'])) {
            $folder = $this->createChannelFolder($channelName);
            $tokenFile = "{$folder}/token.json";
            $token['expiry_date'] = time() + $token['expires_in'];
            file_put_contents($tokenFile, json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /** 認証ファイル(token.json)生成 or 更新（CLI） */
    public function generateAuthFile(string $channelName, bool $forceReauth = false): void
    {
        $folder = $this->createChannelFolder($channelName);
        $tokenFile = "{$folder}/token.json";

        $clientSecret = json_decode(file_get_contents($this->clientSecretFile), true);
        $clientId = $clientSecret['installed']['client_id'];
        $clientSecretKey = $clientSecret['installed']['client_secret'];
        $redirectUri = $clientSecret['installed']['redirect_uris'][0];

        if (file_exists($tokenFile) && !$forceReauth) {
            $token = json_decode(file_get_contents($tokenFile), true);
            if (isset($token['expiry_date']) && time() < $token['expiry_date']) {
                echo "✅ 既存トークン有効: {$channelName}
";
                return;
            } elseif (isset($token['refresh_token'])) {
                echo "🔄 リフレッシュトークンで更新中: {$channelName}
";
                $newToken = $this->refreshAccessToken($clientId, $clientSecretKey, $token['refresh_token']);
                if ($newToken) {
                    $newToken['refresh_token'] = $token['refresh_token'];
                    $newToken['expiry_date'] = time() + $newToken['expires_in'];
                    file_put_contents($tokenFile, json_encode($newToken, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    echo "✅ 更新完了: {$channelName}
";
                    return;
                }
            }
        }

        $authUrl = sprintf(
            'https://accounts.google.com/o/oauth2/v2/auth?response_type=code&access_type=offline&client_id=%s&redirect_uri=%s&scope=%s&prompt=consent',
            urlencode($clientId),
            urlencode($redirectUri),
            urlencode(implode(' ', $this->scopes))
        );

        echo "🔗 認証URLを開いてログインしてください（{$channelName}）:
{$authUrl}
";
        echo "認証コードを入力: ";
        $authCode = trim(fgets(STDIN));

        $token = $this->fetchAccessToken($clientId, $clientSecretKey, $authCode, $redirectUri);
        if (isset($token['error'])) {
            echo "❌ 認証エラー: {$token['error_description']}
";
            return;
        }

        $token['expiry_date'] = time() + $token['expires_in'];
        file_put_contents($tokenFile, json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "✅ 認証完了: {$channelName} -> {$tokenFile}
";
    }

    private function fetchAccessToken(string $clientId, string $clientSecret, string $authCode, string $redirectUri): array
    {
        $data = [
            'code' => $authCode,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ];
        return $this->curlPostJson('https://oauth2.googleapis.com/token', $data);
    }

    private function refreshAccessToken(string $clientId, string $clientSecret, string $refreshToken): ?array
    {
        $data = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];
        $resp = $this->curlPostJson('https://oauth2.googleapis.com/token', $data);
        return isset($resp['access_token']) ? $resp : null;
    }

    private function curlPostJson(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return json_decode($resp, true);
    }

    public function authenticateAll(bool $forceReauth = false): void
    {
        $channels = $this->loadChannels();
        echo "🔹 " . count($channels) . " 件のチャンネル認証を開始します。
";
        foreach ($channels as $ch) {
            $name = $ch['channel'] ?? $ch['name'];
            echo "
▶ {$name}
";
            $this->generateAuthFile($name, $forceReauth);
        }
    }
}

// ===============================
// Webモード処理（edit_actorsから呼び出し）
// ===============================
if (php_sapi_name() !== 'cli') {
    $manager = new YouTubeAuthManager('actors.json', 'client_secret.json', 'oauth');
    $channelId = $_GET['channel_id'] ?? '';
    $authCode  = $_GET['code'] ?? '';
    $state     = $_GET['state'] ?? '';

    if ($authCode && $state) {
        $manager->handleAuthCallback($authCode, $state);
        echo "<h3>✅ 認証完了しました。ウィンドウを閉じてください。</h3>";
        exit;
    }

    if ($channelId) {
        $authUrl = $manager->createAuthUrl($channelId);
        header("Location: {$authUrl}");
        exit;
    }

    echo "<p>❌ channel_id が指定されていません。</p>";
    exit;
}

// ===============================
// CLIモード処理
// ===============================
if (php_sapi_name() === 'cli') {
    $manager = new YouTubeAuthManager('actors.json', 'client_secret.json', 'oauth');
    $manager->authenticateAll(false);
}
?>
