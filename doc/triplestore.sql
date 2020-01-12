CREATE TABLE `prefix` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `prefix` varchar(32) NOT NULL,
  `uri` varchar(255) NOT NULL,
  `label` text NOT NULL ) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `triples` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `graph` bigint(20) UNSIGNED NOT NULL,
  `s` bigint(20) UNSIGNED NOT NULL,
  `p` bigint(20) UNSIGNED NOT NULL,
  `ot` bigint(20) UNSIGNED DEFAULT NULL,
  `od` text ) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `uris` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `prefix` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '' ) ENGINE=MyISAM DEFAULT CHARSET=utf8;

ALTER TABLE `prefix`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `prefix` (`prefix`),
  ADD UNIQUE KEY `uri` (`uri`);

ALTER TABLE `triples`
  ADD PRIMARY KEY (`id`),
  ADD KEY `object` (`ot`),
  ADD KEY `predicate` (`p`),
  ADD KEY `subject` (`s`),
  ADD KEY `graph` (`graph`);

ALTER TABLE `uris`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `fulluri` (`prefix`,`name`) USING BTREE,
  ADD KEY `prefix` (`prefix`),
  ADD KEY `name` (`name`);

ALTER TABLE `prefix`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `triples`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `uris`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `prefix`
  DROP INDEX `prefix`, ADD INDEX `prefix` (`prefix`) USING BTREE; 

COMMIT;

