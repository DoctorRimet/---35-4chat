-- Add avatar and role columns to users table
ALTER TABLE `users` ADD COLUMN `avatar_url` VARCHAR(500) DEFAULT NULL AFTER `username`;
ALTER TABLE `users` ADD COLUMN `user_role` ENUM('user', 'moderator', 'admin') DEFAULT 'user' AFTER `avatar_url`;

-- Set admin role for the first user (adjust ID as needed)
UPDATE `users` SET `user_role` = 'admin' WHERE `id` = 1;