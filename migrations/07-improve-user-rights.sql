CREATE TABLE {prefix}user_right (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_user INT NOT NULL,
    id_charity_event INT,
    id_charity_stream INT,
    CONSTRAINT fk_{prefix}users_id_user_right FOREIGN KEY (id_user) REFERENCES {prefix}users(id)
);

insert into {prefix}user_right (id_user, id_charity_stream)
select u.id, c.id
from {prefix}charity_stream c
inner join {prefix}users u on u.email = c.owner_email;

ALTER TABLE {prefix}charity_stream DROP owner_email;

ALTER TABLE {prefix}users ADD role ENUM('USER', 'ADMIN') NOT NULL DEFAULT('USER') AFTER password;