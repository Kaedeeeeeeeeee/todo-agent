# To Do Agent

Laravel 入門講座をもとに開発する、個人向け To Do アプリです。

- **第1版**：ユーザー認証、フォルダ管理、タスクの作成・編集・削除、期限・状態の管理。
- **第2版（実装中）**：Codex SDK を使い、Gmail と前日の Codex 会話からタスク候補を作成。全量・分割処理、自分で編集できる整理条件、定時実行に対応し、原文を確認・修正してから登録します。Slack 連携と AI による進捗確認は今後実装します。

詳細は [要件定義書](docs/todo-app-requirements.md) を参照してください。

## 構成

| パス | 内容 |
|---|---|
| `Laravel-Tutorial-PJ/src/TaskList/` | 開発対象の Laravel アプリとテスト |
| `Laravel-Tutorial-PJ/compose.yaml` | ローカル開発用の PHP・Nginx・MySQL・Mailpit |
| `Laravel-Tutorial-PJ/docker/` | コンテナのビルド・設定ファイル |
| `docs/todo-app-requirements.md` | アプリの要件定義 |

参考 PDF、旧版の練習プロジェクト、分析結果、バックアップ、実データは管理対象に含めません。既存のローカルディレクトリ構成を保ったまま、上記のファイルを管理します。

## 新しい環境での起動

Docker Compose を利用します。以下は新規クローン後の手順です。既存環境の `.env` は上書きしないでください。

```sh
cd Laravel-Tutorial-PJ
cp .env.example .env
cp src/TaskList/.env.example src/TaskList/.env
```

両方の `.env` を編集し、ローカル開発用のパスワードを設定してください。Compose 側の `MYSQL_PASSWORD` と Laravel 側の `DB_PASSWORD` を一致させます。

```sh
docker compose up -d --build php nginx db mailpit
docker compose exec php composer install --no-interaction
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate
```

- アプリ：[http://localhost:8000](http://localhost:8000)
- 開発用メール確認：[http://localhost:8025](http://localhost:8025)

アプリの登録画面からユーザーを作成できます。タスクの作成・編集・状態変更・削除とフォルダ作成は、一覧画面から操作できます。

フロントエンドはホスト側でビルドします（Node.js 22.19 以上の対応バージョン）。

```sh
cd src/TaskList
npm ci
npm run build
```

## Gmail・Codex 会話からのタスク候補作成

ローカル PC の Codex CLI に ChatGPT でログインし、Gmail プラグインを接続しておきます。Gmail の読み取りとアカウント確認を使います。

`Laravel-Tutorial-PJ/src/TaskList/.env` に以下を設定します。

- `TASK_AGENT_OWNER_ID`：この PC の Gmail・Codex 会話を利用する TaskList ユーザーの ID。
- `TASK_AGENT_ACCOUNT`：Codex で接続済みの Gmail アドレス。接続時に実際のメールアドレスとの一致を確認します。
- `TASK_AGENT_TOKEN`：PHP とローカル読み取りサービス間で共有する、32文字以上のランダムなトークン。
- `TASK_AGENT_CODEX_BIN`：必要な場合のみ Codex CLI の実行ファイルパスを指定。

ホスト側で以下を実行し、利用中だけ起動しておきます。

```sh
cd Laravel-Tutorial-PJ/src/TaskList
npm run agent
```

一覧画面の「メール接続」から接続・解除できます。読み取りサービスはホストの `127.0.0.1:4195` で動き、PHP コンテナからは `TASK_AGENT_URL`（標準：`http://host.docker.internal:4195`）で接続します。

「AI タスク候補」から、次の読み取り元を選べます。

- すべてのメール：実行開始時点から過去24時間のメール本文。迷惑メール・ゴミ箱・添付ファイルは対象外です。
- 前日の Codex 会話：日本時間で前日の0時から24時までに、この PC に保存された会話の依頼と最終回答。ツールログ、システム指示、サブエージェントや ChatGPT プロジェクトのログは含めません。
- メール＋Codex：上記の両方を同じ候補一覧にまとめます。

「整理の条件」に探してほしい内容を自由に入力できます。空欄なら、対応や継続が必要そうな内容を幅広く提案します。対象を全件列挙してから6件ずつ処理し、件数・進捗・実行履歴を保存します。これは1回の実行を6件に制限するものではありません。

一覧は読み取り元ごとの最新実行の候補だけを表示します。履歴データは残し、同じ実行の再試行では途中まで保存した候補も引き継ぎます。AI が示した原文を確認し、タイトル・期限を修正してから追加します。処理中でも保存済みの候補は確認できます。同じ出典からの重複登録を防ぎ、Gmail 接続解除後も保存済みのタスク・候補は残ります。

この接続は本機で設定した1つの TaskList アカウント向けです。任意の Gmail を Google OAuth で追加する機能や、別の端末・クラウドの Codex 会話の同期は含みません。

### 定時整理

「自動整理」で、毎日の実行時刻（日本時間）・読み取り元・整理の条件を保存できます。正式なタスクへの追加には引き続き確認が必要です。ローカルで定時処理やページを閉じた後の続きを実行する間は、`npm run agent` と次のバックグラウンド処理を起動します。

```sh
cd Laravel-Tutorial-PJ
docker compose up -d scheduler
```

利用を終えたら `docker compose stop scheduler` と `npm run agent` の終了で停止します。コンテナの再起動ポリシーは `no` です。

## テスト

```sh
cd Laravel-Tutorial-PJ
docker compose exec php php artisan test --compact
cd src/TaskList
node --test resources/agent/*.test.mjs
npm run build
```

## バージョン管理

リポジトリのルートはこの README のあるディレクトリです。依存パッケージ、`.env`、ログ、データベース、アップロードデータはコミットしません。新しい最上位ディレクトリを管理対象に追加する場合は、ルートの `.gitignore` も更新してください。
