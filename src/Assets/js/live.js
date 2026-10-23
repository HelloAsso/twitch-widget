// Vue "Live" : polling des nouveaux dons + scroll infini vers l'historique.
// Tout le contenu venant des donateurs (pseudo, message) est injecté via
// textContent, jamais innerHTML, pour éviter toute exécution de HTML/JS.

const feed = document.getElementById('donationFeed');
const sentinel = document.getElementById('feedSentinel');
const emptyState = document.getElementById('feedEmptyState');
const endNotice = document.getElementById('feedEndNotice');
const capNotice = document.getElementById('feedCapNotice');

// Tailles de page fixées côté serveur (StreamDonationHistoryRepository) et transmises par la vue
const PAGE_SIZE = window.livePageSize || 30;
const POLL_LIMIT = window.livePollLimit || 100;
const POLL_INTERVAL_MS = 10000;
// Cap du nombre de lignes rendues : au-delà, on arrête de charger plus
// d'historique pour ne pas faire exploser le DOM sur un long live
const MAX_RENDERED_ROWS = 300;

let isFetching = false;
let isLoadingOlder = false;
let newestId = window.newestLoadedId || 0;
let oldestId = window.oldestLoadedId || 0;
let renderedCount = window.initialCount || 0;
let reachedEnd = (window.initialCount || 0) < PAGE_SIZE;

function formatAmount(cents) {
    return (cents / 100).toLocaleString('fr-FR', { minimumFractionDigits: 2 }) + ' €';
}

// Les dates arrivent en ISO 8601 avec fuseau : elles s'affichent dans l'heure du navigateur,
// que le don soit rendu par le serveur au chargement ou ajouté par le polling.
function formatDate(isoDate, withSeconds = true) {
    const date = new Date(isoDate);
    if (isNaN(date.getTime())) {
        return String(isoDate);
    }
    return withSeconds
        ? date.toLocaleString('fr-FR')
        : date.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
}

function localizeServerDates() {
    document.querySelectorAll('.live-time[data-ts]').forEach((element) => {
        element.textContent = formatDate(element.dataset.ts, !element.hasAttribute('data-short'));
    });
}

function buildEntry(entry) {
    const card = document.createElement('div');
    card.className = 'live-entry card mb-2';
    card.dataset.id = entry.id;

    const body = document.createElement('div');
    body.className = 'card-body py-2 px-3';

    const meta = document.createElement('div');
    meta.className = 'd-flex justify-content-between align-items-baseline gap-2';

    const pseudo = document.createElement('span');
    pseudo.className = 'fw-bold';
    pseudo.textContent = entry.pseudo;

    const amount = document.createElement('span');
    amount.className = 'badge bg-success';
    amount.style.fontSize = '0.9rem';
    amount.textContent = formatAmount(entry.amount);

    const time = document.createElement('span');
    time.className = 'text-muted small ms-auto';
    time.textContent = formatDate(entry.received_at);

    meta.append(pseudo, amount, time);
    body.append(meta);

    if (entry.message) {
        const message = document.createElement('p');
        message.className = 'mb-0 mt-1';
        message.style.opacity = '0.85';
        message.textContent = entry.message;
        body.append(message);
    }

    card.append(body);
    return card;
}

// Quand les nouveaux dons font dépasser le cap, on retire les plus anciens du bas du fil.
// L'historique au-delà n'est alors plus chargeable : on le signale comme au cap du scroll.
function trimOverflow() {
    if (renderedCount <= MAX_RENDERED_ROWS) {
        return;
    }

    const rows = feed.querySelectorAll('.live-entry');
    for (let i = rows.length - 1; i >= MAX_RENDERED_ROWS; i--) {
        rows[i].remove();
    }
    renderedCount = MAX_RENDERED_ROWS;
    oldestId = Number(rows[MAX_RENDERED_ROWS - 1].dataset.id);

    reachedEnd = true;
    observer.disconnect();
    if (endNotice) {
        endNotice.style.display = 'none';
    }
    if (capNotice) {
        capNotice.style.display = 'block';
    }
}

async function fetchNewDonations() {
    if (isFetching) {
        return;
    }
    isFetching = true;
    let hasMore = false;

    try {
        const response = await fetch(`/admin/stream/${window.charityStreamId}/live/fetch?since_id=${newestId}`);
        if (!response.ok) {
            console.error('Erreur lors de la récupération des dons du live :', response.status);
            return;
        }

        const entries = await response.json();
        if (!Array.isArray(entries) || entries.length === 0) {
            return;
        }

        if (emptyState) {
            emptyState.style.display = 'none';
        }

        // Le serveur renvoie les dons par id croissant : en préprendant chacun
        // dans l'ordre, le plus récent finit tout en haut du fil
        for (const entry of entries) {
            feed.prepend(buildEntry(entry));
            newestId = Math.max(newestId, entry.id);
            if (oldestId === 0) {
                oldestId = entry.id;
            }
            renderedCount++;
        }
        trimOverflow();

        // Le serveur borne chaque réponse : tant qu'elle est pleine, il reste du retard à rattraper
        hasMore = entries.length >= POLL_LIMIT;
    } catch (error) {
        console.error('Erreur réseau lors de la récupération des dons du live :', error);
    } finally {
        isFetching = false;
    }

    if (hasMore) {
        fetchNewDonations();
    }
}

async function fetchOlderDonations() {
    if (isLoadingOlder || reachedEnd || oldestId === 0) {
        return;
    }

    if (renderedCount >= MAX_RENDERED_ROWS) {
        observer.disconnect();
        if (capNotice) {
            capNotice.style.display = 'block';
        }
        return;
    }

    isLoadingOlder = true;

    try {
        const response = await fetch(`/admin/stream/${window.charityStreamId}/live/history?before_id=${oldestId}`);
        if (!response.ok) {
            console.error('Erreur lors du chargement de l\'historique du live :', response.status);
            return;
        }

        const entries = await response.json();
        if (!Array.isArray(entries)) {
            return;
        }

        // Les pages arrivent par id décroissant : on les ajoute à la suite du fil
        for (const entry of entries) {
            feed.insertBefore(buildEntry(entry), sentinel);
            oldestId = entry.id;
            renderedCount++;
        }

        if (entries.length < PAGE_SIZE) {
            reachedEnd = true;
            observer.disconnect();
            if (endNotice && renderedCount > 0) {
                endNotice.style.display = 'block';
            }
        }
    } catch (error) {
        console.error('Erreur réseau lors du chargement de l\'historique du live :', error);
    } finally {
        isLoadingOlder = false;
    }
}

const observer = new IntersectionObserver((observedEntries) => {
    if (observedEntries.some((observed) => observed.isIntersecting)) {
        fetchOlderDonations();
    }
});

localizeServerDates();

if (feed && window.liveStarted) {
    fetchNewDonations();
    setInterval(fetchNewDonations, POLL_INTERVAL_MS);

    if (sentinel && !reachedEnd) {
        observer.observe(sentinel);
    }
}
