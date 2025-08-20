-- WebP Safe Migrator - MySQL Initialization Script
-- This script sets up the database with proper configuration for the plugin

-- Ensure UTF8MB4 support for full Unicode compatibility
ALTER DATABASE wordpress_webp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create additional user for plugin testing (optional)
CREATE USER IF NOT EXISTS 'webp_test'@'%' IDENTIFIED BY 'webp_test123';
GRANT ALL PRIVILEGES ON wordpress_webp_test.* TO 'webp_test'@'%';

-- Optimize MySQL settings for development
SET GLOBAL max_allowed_packet = 67108864; -- 64MB
SET GLOBAL innodb_buffer_pool_size = 268435456; -- 256MB

-- Create indexes for better performance (WordPress will create these, but good to have)
-- These will be created by WordPress, but we can prepare for them

-- Log the initialization
INSERT INTO mysql.general_log (event_time, user_host, thread_id, server_id, command_type, argument)
VALUES (NOW(), 'webp-migrator-init', 0, 1, 'Query', 'WebP Safe Migrator database initialized')
ON DUPLICATE KEY UPDATE argument = 'WebP Safe Migrator database re-initialized';

-- Flush privileges to ensure all changes take effect
FLUSH PRIVILEGES;
