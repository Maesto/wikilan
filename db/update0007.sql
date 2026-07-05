-- Buddy seats: a seat row like 'A1b' with buddy_of='A1' is a second, plan-
-- invisible spot at the same table. It renders only while occupied (the host
-- circle shifts aside to make room) and is filled via the holder's share
-- action or an organizer assignment, never by direct reservation.
ALTER TABLE seats ADD COLUMN buddy_of TEXT;
