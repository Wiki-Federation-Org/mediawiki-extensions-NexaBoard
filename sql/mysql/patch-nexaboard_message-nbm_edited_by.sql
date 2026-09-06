-- Adds nbm_edited_by so an edit records who made it, not only when.
ALTER TABLE /*_*/nexaboard_message
  ADD COLUMN nbm_edited_by INT UNSIGNED DEFAULT NULL;
