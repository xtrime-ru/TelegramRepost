# TelegramRepost

## Setup
1. `docker-compose build --pull`
2. `cp .env.example .env`
3. Edit config: `nano .env`
   1. Obtain `TELEGRAM_API_ID` and `TELEGRAM_API_HASH` from https://my.telegram.org/
   2. Check `RECIPIENTS` and `KEYWORDS` in `.env`
4. Run interactive shell and login to account in cli: `docker-compose run tg-repost`
5. stop container: `CTRL+C`
6. Start containers in background: `docker-compose up -d`