import { CountUp } from 'countup.js';
import updateDonationBar, { triggerGoalAnimation } from './utilities.js';

let counterback;
let counterfront;
let isFetching = false;

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
    if (isFetching) {
        console.warn('Un appel est déjà en cours, ignoré');
        return;
    }

    isFetching = true;

    try {
        const response = await fetch(`/widget-stream-donation/${window.charityStreamId}/fetch`);

        if (response.ok) {
            const json = await response.json();

            const justCrossedGoal =
                json.goal !== window.goalAmount ||
                (json.allGoalsReached && window.currentAmount / 100 < window.goalAmount);

            window.currentAmount = json.amount;

            if (justCrossedGoal) {
                triggerGoalAnimation(json.goal, counterback, counterfront, json.allGoalsReached);
            } else {
                updateDonationBar(counterback, counterfront);
            }
        } else {
            console.error('Erreur lors de la récupération des données de donation:', response.status);
        }
    } catch (error) {
        console.error('Erreur réseau lors de la récupération des données:', error);
    } finally {
        isFetching = false;
    }
}

export {
    fetchDonationData as fetch
};
