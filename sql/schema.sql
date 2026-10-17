-- state of nkp-musicbrainz, created in the schema from the config (search_path is set to it)

CREATE TABLE IF NOT EXISTS meta (
	key text PRIMARY KEY,
	value text NOT NULL
);

CREATE TABLE IF NOT EXISTS record (
	cnb text PRIMARY KEY, -- national bibliography number (015 $a)
	data jsonb NOT NULL, -- parsed fields, see Marc\Record
	imported_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS artist_authority (
	authority text NOT NULL, -- NK ČR authority ID (Wikidata P691)
	artist_gid uuid NOT NULL, -- MusicBrainz artist ID (Wikidata P434)
	PRIMARY KEY (authority, artist_gid)
);

CREATE TABLE IF NOT EXISTS pairing (
	cnb text PRIMARY KEY REFERENCES record (cnb) ON DELETE CASCADE,
	version integer NOT NULL,
	data jsonb NOT NULL -- method, paired release and release group, artists, labels, MusicBrainz snapshot
);

CREATE TABLE IF NOT EXISTS proposal (
	key text PRIMARY KEY, -- hash of cnb, kind, target and proposed value
	cnb text NOT NULL REFERENCES record (cnb) ON DELETE CASCADE,
	kind text NOT NULL,
	target uuid, -- paired release, NULL for a new release
	current jsonb NOT NULL, -- field => MusicBrainz value
	proposed jsonb NOT NULL, -- field => new value, plus edit data under "edit"
	reason text -- why it can't be processed, NULL if it can
);

CREATE INDEX IF NOT EXISTS proposal_cnb_idx ON proposal (cnb);

DO $$ BEGIN
	CREATE TYPE decision_state AS ENUM ('ignored', 'processed', 'previewed', 'failed');
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

CREATE TABLE IF NOT EXISTS decision (
	key text PRIMARY KEY, -- proposal key, kept when proposals are regenerated
	cnb text NOT NULL,
	kind text NOT NULL,
	state decision_state NOT NULL,
	edits jsonb NOT NULL DEFAULT '[]', -- edit IDs on MusicBrainz
	message text,
	votable boolean NOT NULL DEFAULT false,
	batch integer,
	decided_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS decision_batch_idx ON decision (batch);

DO $$ BEGIN
	CREATE TYPE manual_pairing_entity AS ENUM ('release', 'release-group', 'new');
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

CREATE TABLE IF NOT EXISTS manual_pairing (
	cnb text PRIMARY KEY REFERENCES record (cnb) ON DELETE CASCADE,
	entity manual_pairing_entity NOT NULL, -- release-group: a new release in that release group, new: not on MusicBrainz
	gid uuid,
	created_at timestamptz NOT NULL DEFAULT now(),
	CONSTRAINT manual_pairing_gid_check CHECK ((gid IS NULL) = (entity = 'new'))
);

ALTER TABLE decision ADD COLUMN IF NOT EXISTS preview text; -- edits rendered by MusicBrainz in the preview mode

CREATE TABLE IF NOT EXISTS obalky_knih (
	cnb text PRIMARY KEY REFERENCES record (cnb) ON DELETE CASCADE,
	found boolean NOT NULL, -- obalkyknih.cz has an entry
	cover boolean NOT NULL,
	checked_at timestamptz NOT NULL DEFAULT now()
);
