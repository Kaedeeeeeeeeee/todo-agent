# To Do Agent

Laravel 入門講座をもとに開発する、個人向け To Do アプリです。

- **第1版**：ユーザー認証、フォルダ管理、タスクの作成・編集・削除、期限・状態の管理。
- **第2版（計画）**：Codex SDK を使った Gmail・Slack からのタスク整理と、AI による進捗確認。

詳細は [要件定義書](学习资料/ToDo_APP_要件定義.md) を参照してください。

## 構成

| パス | 内容 |
|---|---|
| `Laravel-Tutorial-PJ/src/TaskList/` | 開発対象の Laravel アプリとテスト |
| `Laravel-Tutorial-PJ/compose.yaml` | ローカル開発用の PHP・Nginx・MySQL・Mailpit |
| `Laravel-Tutorial-PJ/docker/` | コンテナのビルド・設定ファイル |
| `学习资料/ToDo_APP_要件定義.md` | アプリの要件定義 |

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

アプリの登録画面からユーザーを作成できます。第2版の外部アカウント連携・AI 機能は今後実装します。

## テスト

```sh
cd Laravel-Tutorial-PJ
docker compose exec php php artisan test --compact
```

## バージョン管理

リポジトリのルートはこの README のあるディレクトリです。依存パッケージ、`.env`、ログ、データベース、アップロードデータはコミットしません。新しい最上位ディレクトリを管理対象に追加する場合は、ルートの `.gitignore` も更新してください。
