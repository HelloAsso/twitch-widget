var alertQueue = [];
var isAlertActive = false;
var isFetching = false;

fetchAlerts();
setInterval(fetchAlerts, 10000);

function displayAlertBox(pseudo, message, amount) {
    message = message.substring(0, 255);
    alertQueue.push({ pseudo, message, amount });

    if (!isAlertActive) {
        processAlertQueue();
    }
}

function processAlertQueue() {
    if (alertQueue.length === 0) {
        isAlertActive = false;
        return;
    }

    isAlertActive = true;
    const alert = alertQueue.shift();
    const { pseudo, message, amount } = alert;
    var container = document.querySelector('.widget-alert-box');
    container.innerHTML = '';

    let mediaElement;
    if (window.image.endsWith('.mp4')) {
        mediaElement = document.createElement('video');
        mediaElement.src = window.image;
        mediaElement.autoplay = true;
        mediaElement.loop = true;
        mediaElement.muted = false;
        mediaElement.style.maxWidth = '100%';
        mediaElement.classList.add('fade');
    } else {
        mediaElement = document.createElement('img');
        mediaElement.src = window.image;
        mediaElement.style.maxWidth = '100%';
        mediaElement.classList.add('fade');
    }

    container.appendChild(mediaElement);

    let eur = new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'EUR',
    });
    var messageTemplate = document.createElement('p');
    const escapeHtml = (str) => String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    messageTemplate.innerHTML = window.message_template
        .replace("{pseudo}", escapeHtml(pseudo))
        .replace("{message}", escapeHtml(message))
        .replace("{amount}", eur.format(amount / 100));
    messageTemplate.style.marginTop = '10px';
    messageTemplate.classList.add('fade');
    container.appendChild(messageTemplate);
    var audio = new Audio(window.sound);

    setTimeout(function () {
        mediaElement.classList.add('show');
        messageTemplate.classList.add('show');

        audio.volume = window.sound_volume;
        audio.play();
    }, 100)

    setTimeout(function () {
        mediaElement.classList.remove('show');
        messageTemplate.classList.remove('show');
        audio.pause();
        audio.currentTime = 0;

        container.innerHTML = '';
        processAlertQueue();
    }, window.alert_duration);
}

async function fetchAlerts() {
    if (!window.charityStreamId) {
        return;
    }

    // Éviter les appels concurrents
    if (isFetching) {
        console.warn('Un appel est déjà en cours, ignoré');
        return;
    }

    isFetching = true;

    try {
        const response = await fetch(`/widget-stream-alert/${window.charityStreamId}/fetch`);

        if (response.ok) {
            const json = await response.json();
            json.donations.forEach(donation => {
                displayAlertBox(donation.pseudo, donation.message, donation.amount);
            });
        } else {
            console.error('Erreur lors de la récupération des données de donation:', response.status);
        }
    } catch (error) {
        console.error('Erreur réseau lors de la récupération des données:', error);
    } finally {
        isFetching = false; // Réinitialiser le flag
    }
}

export {
    displayAlertBox,
    fetchAlerts as fetch
};