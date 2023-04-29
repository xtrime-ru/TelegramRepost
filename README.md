# TelegramRepost

Check messages with regular expressions and forward them to selected chats/groups.

## Setup
0. Install docker
   ```shell
   curl -fsSL https://get.docker.com -o get-docker.sh
   sudo sh ./get-docker.sh
   ```
1. `git clone https://github.com/xtrime-ru/TelegramRepost.git && cd TelegramRepost`
2. `docker compose pull`
3. `cp .env.example .env`
4. Edit config `.env`:
   1. Obtain `TELEGRAM_API_ID` and `TELEGRAM_API_HASH` from https://my.telegram.org/
   2. Check `RECIPIENTS` and `KEYWORDS` in `.env`
5. Run interactive shell and login to account in cli: `docker compose run --rm tg-repost`
6. stop container: `CTRL+C`
7. Start containers in background: `docker compose up -d`
8. Always restart container after .env update: `docker compose restart tg-repost`

## Database
MadelineProto uses mysql to store its session and cache. It also able to store user data.
By default, tg-repost will start own mariadb container at root@127.0.0.1:10306. Password is empty string.

Currently, all data in db is serialized.
### Messages
tg-repost can save all incoming messages/updates to madelineProto database.
Enable SAVE_MESSAGES in .env. 
Check table "$YourID_EventHandler_messages_db"
### Sources
You can add sources list to table "$YourID_EventHandler_sources_db". 
tg-repost will check this table every 60 seconds and update list of listening channels.
