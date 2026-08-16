-- Adds the date the research itself was completed, as distinct from
-- upload_date (when it was submitted to the repository).
--
-- Existing rows have no month/day on record, so research_date stays NULL and
-- the UI falls back to the `year` column for those papers.
ALTER TABLE research_papers
    ADD COLUMN research_date DATE NULL DEFAULT NULL AFTER year;

-- Filtering and sorting hit this column on every browse query.
CREATE INDEX idx_research_papers_research_date ON research_papers (research_date);
