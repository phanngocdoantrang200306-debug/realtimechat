# RealTimeChat - Frontend

Frontend MVP for the RealTime Chat project.

## Stack
- HTML5
- CSS3
- Vanilla JavaScript
- No frontend framework
- No external UI dependency

## Current frontend features
- Enter nickname
- Lobby
- Public/private room cards
- Create room modal
- Join room
- Chat interface
- Online member list
- Typing indicator UI
- Responsive desktop/mobile layout
- Demo messages using local browser state

## Backend integration points
The frontend is intentionally prepared for a PHP + Ratchet + SQL Server backend.

Recommended WebSocket events:
- `join_room`
- `user_joined`
- `online_users`
- `send_message`
- `new_message`
- `typing`
- `user_left`
- `leave_room`
- `disconnect`

The TODO comments in `js/app.js` show where WebSocket messages can be connected.

## Run
Open `index.html` directly in a browser for the frontend demo.

For the real application, serve the project through the PHP web server and connect the JavaScript WebSocket client to the PHP Ratchet server.
