-- Adds nbm_edited_by so an edit records who made it, not only when.
ALTER TABLE nexaboard_message
  ADD COLUMN nbm_edited_by INT DEFAULT NULL;
