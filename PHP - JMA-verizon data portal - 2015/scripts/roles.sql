/*
DROP TABLE role_permission;
DROP TABLE user_role;
DROP TABLE roles;
DROP TABLE permissions;
*/

/* modify users.activated and users.admin.. idiorm does not handle bit fields */
alter table users modify column activated tinyint default 0;
alter table users modify column admin tinyint default 0;
alter table users add column Address1 varchar(50) not null;
alter table users add column Address2 varchar(50);
alter table users add column FullName varchar(50) not null;
alter table users add column cellphone varchar(32) not null;
alter table users change phone workphone varchar(32) not null;
alter table users add column usergroup varchar(32) not null;  /* group is reserved word -> usergroup */
alter table users add column firstname varchar(32) not null;
alter table users add column lastname varchar(32) not null;

/* departments ??? */
CREATE TABLE IF NOT EXISTS user_groups (
	group_id INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
	group_name VARCHAR(32) NOT NULL,
	group_description VARCHAR(50),
	PRIMARY KEY (group_id)
);

insert into user_groups values (null, 'group01', null);
insert into user_groups values (null, 'group02', null);
insert into user_groups values (null, 'group03', null);


CREATE TABLE IF NOT EXISTS roles (
	role_id INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
	role_name VARCHAR(50) NOT NULL,
	role_description VARCHAR(50) NOT NULL,
	EditedBy VARCHAR(15),
	PRIMARY KEY (role_id)
);

CREATE TABLE IF NOT EXISTS permissions (
	permission_id INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
	permission_description VARCHAR(50) NOT NULL,
	EditedBy VARCHAR(15),
	PRIMARY KEY (permission_id)
);

CREATE TABLE IF NOT EXISTS role_permission (
	id INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
	role_id INTEGER UNSIGNED NOT NULL,
	permission_id INTEGER UNSIGNED NOT NULL,
	UNIQUE KEY role_perm_idx (role_id, permission_id),
	CONSTRAINT role_permission5_ibfk_1 FOREIGN KEY (role_id) REFERENCES roles (role_id),
	CONSTRAINT role_permission5_ibfk_2 FOREIGN KEY (permission_id) REFERENCES permissions (permission_id),
	PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS user_role (
  id INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id INTEGER UNSIGNED NOT NULL,
	role_id INTEGER UNSIGNED NOT NULL,
	UNIQUE KEY user_role_idx (user_id, role_id),
	PRIMARY KEY (id)
);

insert into permissions values (null, 'Read', null);
insert into permissions values (null, 'Write', null);
insert into permissions values (null, 'Delete', null);
insert into permissions values (null, 'Update', null);
insert into permissions values (null, 'Create', null);
insert into permissions values (null, 'User Management', null);
insert into permissions values (null, 'Reports', null);

/* Don't put blanks in role names */
/* they are used to create html form id's and blanks cut off parts of it */
/* id="radio_RO_5" would possibly be something like id="radio_RO User_5" */
insert into roles values (null, 'Read-Only', 'Read-only user', null);
insert into roles values (null, 'Read-Write', 'Regular user', null);
insert into roles values (null, 'VZ-Admin', 'Administrator', null);
insert into roles values (null, 'JMA-Admin', 'Uber admin - JMA only', null);

/* test accounts and roles */
insert into user_role values(null, 5, 4);    /* admin user, JMA role */
insert into user_role values(null, 10, 3);   /* Eugene, admin */
insert into user_role values(null, 15, 2);   /* Mark, RW user */
insert into user_role values(null, 21, 1);   /* Marcel, RO user */

insert into role_permission values(null,4,1); /* JMA role perms */
insert into role_permission values(null,4,2);
insert into role_permission values(null,4,3);
insert into role_permission values(null,4,4);
insert into role_permission values(null,4,5);
insert into role_permission values(null,4,6);
insert into role_permission values(null,3,1); /* admin role perms */
insert into role_permission values(null,3,2);
insert into role_permission values(null,3,3);
insert into role_permission values(null,3,4);
insert into role_permission values(null,3,5);
insert into role_permission values(null,2,1); /* RW User role perms */
insert into role_permission values(null,2,2);
insert into role_permission values(null,1,1); /* RO User role perms */


