# Plugin Ariston pour Jeedom

Plugin Jeedom permettant de piloter une chaudière Ariston connectée (Genus, Clas, Alteas, et autres modèles compatibles) via l'API Ariston NET (ariston-net.remotethermo.com).

## Fonctionnalités

- Suivi en temps réel : température de départ chauffage, température ambiante, pression, modulation, code erreur, signal WiFi
- Eau chaude sanitaire (ECS) : température actuelle et consigne
- Support multi-circuits de chauffage : suivi et pilotage indépendant de chaque zone/circuit si votre installation en comporte plusieurs
- Réglage à distance : consigne chauffage, consigne ECS, mode (Été / Hiver / Chauffage seul / Arrêt)
- Suivi de consommation : gaz et électricité (chauffage et ECS), au jour

## Prérequis

- Un compte Ariston NET actif (le même que celui utilisé dans l'application mobile Ariston NET)
- Jeedom en version 4.2 ou supérieure
- Python 3 (installé automatiquement via les dépendances du plugin)

## Installation

Depuis Jeedom : **Plugins → Gestion des plugins → Ajouter depuis une source**, puis renseignez :
- Utilisateur : `titof2375`
- Dépôt : `plugin-ariston`
- Branche : `master`

## Configuration

1. Renseignez votre email et mot de passe Ariston NET dans la configuration du plugin
2. Le démon se lance automatiquement et détecte votre/vos chaudière(s)
3. Un équipement est créé automatiquement par chaudière détectée sur votre compte

## Support

Ce plugin n'est pas un plugin officiel Ariston ni Jeedom. En cas de souci, ouvrez une issue sur ce dépôt GitHub.

## Licence

AGPL
