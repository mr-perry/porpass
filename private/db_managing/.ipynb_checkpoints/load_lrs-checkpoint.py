import os
from pathlib import Path
from dotenv import load_dotenv

# Locate .env two levels up from private/db_managing/
env_path = Path(__file__).resolve().parents[2] / '.env'
load_dotenv(env_path)

# Then access values like this
db_host = os.getenv('DB_HOST')
db_name = os.getenv('DB_DATABASE')
db_user = os.getenv('DB_USERNAME')
db_pass = os.getenv('DB_PASSWORD')

def seed_lrs_observations():
    return