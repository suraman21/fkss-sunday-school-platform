# Browser tests with a MOCK API (developer tool)
`server.js` serves `frontend/` and fakes the API with made-up data (port 8766). It tests page layout and interactions
WITHOUT PHP or MySQL. It does NOT test the real backend or database.
Setup: `npm i playwright` then `npx playwright install chromium`. Run `node server.js`, then in another window `node run.js` and `node interact.js`.
