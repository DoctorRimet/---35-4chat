-- Add status column to posts table for drafts
ALTER TABLE `posts` ADD COLUMN `status` ENUM('draft', 'published') DEFAULT 'published' AFTER `content`;