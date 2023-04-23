# TelegramRepost

Check messages with regular expressions and forward them to selected chats/groups.

## Setup
0. Install docker
   ```shell
   curl -fsSL https://get.docker.com -o get-docker.sh
   sudo sh ./get-docker.sh --dry-run
   ```
1. `git clone https://github.com/xtrime-ru/TelegramRepost.git && cd TelegramRepost`
2. `docker-compose pull`
3. `cp .env.example .env`
4. Edit config `.env`:
   1. Obtain `TELEGRAM_API_ID` and `TELEGRAM_API_HASH` from https://my.telegram.org/
   2. Check `RECIPIENTS` and `KEYWORDS` in `.env`
5. Run interactive shell and login to account in cli: `docker compose run --rm tg-repost`
6. stop container: `CTRL+C`
7. Start containers in background: `docker compose up -d`
8. Always restart container after .env update: `docker compose restart tg-repost`