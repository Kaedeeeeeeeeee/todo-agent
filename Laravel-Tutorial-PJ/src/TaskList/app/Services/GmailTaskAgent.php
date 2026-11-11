<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GmailTaskAgent
{
    public function start(string $id, string $scope): array
    {
        return $this->request('post', '/runs/'.$id, ['scope' => $scope]);
    }

    public function verify(string $id): array
    {
        return $this->request('post', '/runs/'.$id, ['kind' => 'verify']);
    }

    public function result(string $id): array
    {
        return $this->request('get', '/runs/'.$id);
    }

    public function health(): array
    {
        return $this->request('get', '/health');
    }

    public function cancel(string $id): array
    {
        return $this->request('delete', '/runs/'.$id);
    }

    private function request(string $method, string $path, array $data = []): array
    {
        try {
            $response = Http::withToken(config('services.task_agent.token'))
                ->timeout($path === '/health' ? 2 : 5)->{$method}(rtrim(config('services.task_agent.url'), '/').$path, $data);
        } catch (ConnectionException) {
            throw new TaskAgentException('メール読み取りサービスに接続できません。サービスの起動状態を確認してください。');
        }
        if ($response->status() === 429) {
            throw new TaskAgentException('前の処理が続いているか、連続読み取りの待機時間中です。少し待ってから再試行してください。', 'BUSY', max(1, (int) $response->json('retry_after', 5)));
        }
        if (! $response->successful() || ! is_array($response->json())) {
            Log::warning('Task agent request rejected', ['method' => $method, 'status' => $response->status()]);
            throw new TaskAgentException('メール読み取りサービスの応答を確認できません。接続状態を再確認してください。');
        }

        return $response->json();
    }
}
