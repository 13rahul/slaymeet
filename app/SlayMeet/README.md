# SlayMeet (UltraMeet)

Video meetings, waiting room, LiveKit, recordings, agent TTS.

## Env vars

- `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET`

## Dashboard

- `public/dashboard/slaymeet.php`
- `public/dashboard/meet-recording.php`

## Assets

- `public/assets/js/slaymeet-livekit.js`, `slaymeet-agent.js`, `slaymeet-gallery.js`
- `public/assets/css/pages/slaymeet-room.css`, `slaymeet-call-ui.css`

## Cron

- `public/cron/slaymeet-signal-prune.php`

## Domain / infrastructure (moved from `app/includes/`)

- `Domain/slaymeet_helpers.php` — `SlayMeetHelpers`
- `Domain/SlayMeetAgent.php`, `slaymeet_calls_schema.php`, `workplace_meet_recording_helper.php`
- `Infrastructure/slaymeet_incoming_calls_client.php`
- `Infrastructure/Speech/SlayMeet{Speech,GeminiSpeech,PiperSpeech}.php`
- `Domain/SignalService.php`, `RoomService.php` (phase 2 facades)

## API endpoints

Public stub → `Http/Api/Actions/*Action` → `Http/Api/{script}.php`

| Endpoint | Action class |
|----------|----------------|
| `join_room.php` | `JoinRoomAction` |
| `leave_room.php` | `LeaveRoomAction` |
| `create_room.php` | `CreateRoomAction` |
| `room_state.php` | `RoomStateAction` |
| `check_admission.php` | `CheckAdmissionAction` |
| `update_admission.php` | `UpdateAdmissionAction` |
| `initiate_call.php` | `InitiateCallAction` |
| `answer_call.php` | `AnswerCallAction` |
| `cancel_call.php` | `CancelCallAction` |
| `end_call.php` | `EndCallAction` |
| `get_call_status.php` | `GetCallStatusAction` |
| `poll_call_status.php` | `PollCallStatusAction` |
| `poll_calls.php` | `PollCallsAction` |
| `call_history.php` | `CallHistoryAction` |
| `signal_send.php` | `SignalSendAction` |
| `signal_poll.php` | `SignalPollAction` |
| `signal_stream.php` | `SignalStreamAction` |
| `bot_signal_send.php` | `BotSignalSendAction` |
| `agent_respond.php` | `AgentRespondAction` |
| `agent_tts.php` | `AgentTtsAction` |
| `invite_agent.php` | `InviteAgentAction` |
| `save_transcript.php` | `SaveTranscriptAction` |
| `upload_recording.php` | `UploadRecordingAction` |
| `upload_file.php` | `UploadFileAction` |

## Smoke test

1. Open dashboard → UltraMeet room
2. `POST /api/slaymeet/join_room.php` (from UI)
3. `GET /api/slaymeet/room_state.php?room=…`
