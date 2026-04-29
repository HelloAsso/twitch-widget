import { CountUp } from 'countup.js';
import updateDonationBar from './utilities.js';

let counterback;
let counterfront;
let isFetching = false; // Flag pour éviter les appels concurrents

if (typeof CountUp !== 'undefined' && CountUp) {
    const options = {
        separator: ' ',
        decimal: ',',
        suffix: ' €',
    };
    counterback = new CountUp('back-goal-current', window.currentAmount, options);
    counterfront = new CountUp('front-goal-current', window.currentAmount, options);
}

if (counterback && counterfront) {
    updateDonationBar(counterback, counterfront);
}

fetchDonationData();
setInterval(fetchDonationData, 10000);

async function fetchDonationData() {
    // Éviter les appels concurrents
    if (isFetching) {
        console.warn('Un appel est déjà en cours, ignoré');
        return;
    }

    isFetching = true;

    try {
        const response = await fetch(`/widget-stream-donation/${window.charityStreamId}/fetch`);

        if (response.ok) {
            const json = await response.json();
            window.currentAmount = json.amount;
            updateDonationBar(counterback, counterfront);
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
    fetchDonationData as fetch
};