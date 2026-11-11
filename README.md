# To Do Agent

Laravel 入門講座をもとに開発する、個人向け To Do アプリです。

- **第1版**：ユーザー認証、フォルダ管理、タスクの作成・編集・削除、期限・状態の管理。
- **第2版（実装中）**：Codex SDK と接続済み Gmail からタスク候補を作成し、原文を確認・修正してから登録。定時実行、Slack 連携、AI による進捗確認は今後実装。

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
docker compose up -d --build
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

## Gmail からのタスク候補作成

ローカル PC の Codex CLI に ChatGPT でログインし、Gmail プラグインを接続しておきます。Gmail の読み取りとアカウント確認を使います。

`Laravel-Tutorial-PJ/src/TaskList/.env` に以下を設定します。

- `TASK_AGENT_OWNER_ID`：この Gmail を利用する TaskList ユーザーの ID。
- `TASK_AGENT_ACCOUNT`：Codex で接続済みの Gmail アドレス。接続時に実際のメールアドレスとの一致を確認します。
- `TASK_AGENT_TOKEN`：PHP とローカル読み取りサービス間で共有する、32文字以上のランダムなトークン。
- `TASK_AGENT_CODEX_BIN`：必要な場合のみ Codex CLI の実行ファイルパスを指定。

ホスト側で以下を実行し、利用中だけ起動しておきます。

```sh
cd Laravel-Tutorial-PJ/src/TaskList
npm run agent
```

一覧画面の「メール接続」から接続・解除できます。読み取りサービスはホストの `127.0.0.1:4195` で動き、PHP コンテナからは `TASK_AGENT_URL`（標準：`http://host.docker.internal:4195`）で接続します。

現在は手動実行で、最近30日の GitHub コードレビューまたは Bizmates 社内メールを最新6件まで読み取ります。AI が候補と原文の根拠を提示し、ユーザーがタイトル・期限を確認してから既存フォルダへ追加します。同じメールからの重複作成を防ぎます。接続解除後も保存済みのタスク・候補は残ります。

この接続は本機で設定した1つの TaskList アカウント向けです。Google OAuth で任意の Gmail を直接追加する機能や、定時実行はまだ含みません。

## テスト

```sh
cd Laravel-Tutorial-PJ
docker compose exec php php artisan test --compact
cd src/TaskList
node --test resources/agent/gmail-agent.test.mjs
npm run build
```

## バージョン管理

リポジトリのルートはこの README のあるディレクトリです。依存パッケージ、`.env`、ログ、データベース、アップロードデータはコミットしません。新しい最上位ディレクトリを管理対象に追加する場合は、ルートの `.gitignore` も更新してください。
