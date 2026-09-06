-- The character limits are validated with mb_strlen but were stored in columns
-- sized in bytes, so a multibyte title or body was truncated mid-character and
-- the resulting invalid UTF-8 made the whole board fail to render. Widen both
-- to 4 bytes per permitted character.
ALTER TABLE /*_*/nexaboard_thread
  MODIFY nbt_title VARBINARY(800) NOT NULL;

ALTER TABLE /*_*/nexaboard_message
  MODIFY nbm_body MEDIUMBLOB NOT NULL;
