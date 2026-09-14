import * as faceapi from 'face-api.js'

const MODEL_URL = '/models'
const DETECTOR_OPTIONS = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 })

// face-api.js's own recommended threshold for its 128-d descriptors.
export const MATCH_THRESHOLD = 0.6

let loadPromise = null

export function loadFaceModels() {
  if (!loadPromise) {
    loadPromise = Promise.all([
      faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
      faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
      faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
    ])
  }
  return loadPromise
}

// input: HTMLImageElement | HTMLVideoElement | HTMLCanvasElement
// returns a plain number[] (128-d) or null if no face was found. Kept for
// any caller that only needs the descriptor with no quality gate.
export async function getFaceDescriptor(input) {
  const result = await faceapi.detectSingleFace(input, DETECTOR_OPTIONS).withFaceLandmarks().withFaceDescriptor()
  return result ? Array.from(result.descriptor) : null
}

export function faceDistance(a, b) {
  return faceapi.euclideanDistance(a, b)
}

export function isMatch(a, b) {
  return faceDistance(a, b) <= MATCH_THRESHOLD
}

function frameSize(input) {
  return { width: input.videoWidth || input.width, height: input.videoHeight || input.height }
}

/**
 * Detection + landmarks only — no descriptor. This is the one to poll
 * continuously for live guidance/turn-tracking while the camera preview is
 * up: skipping the descriptor (a full ResNet forward pass, the most
 * expensive step) keeps each poll fast enough to run several times a
 * second.
 *
 * @param {HTMLVideoElement|HTMLCanvasElement} input
 * @returns {Promise<{state: 'no-face'|'multiple-faces'|'ok', issues: string[], ear?: number, yaw?: number}>}
 */
export async function detectFaceLive(input) {
  const results = await faceapi.detectAllFaces(input, DETECTOR_OPTIONS).withFaceLandmarks()

  if (results.length === 0) {
    return { state: 'no-face', issues: ['No face detected. Make sure your face is visible and well lit.'] }
  }
  if (results.length > 1) {
    return { state: 'multiple-faces', issues: ['Multiple faces detected. Only one person should be in the frame.'] }
  }

  const { detection, landmarks } = results[0]
  const { width, height } = frameSize(input)
  const ear = averageEAR(landmarks)
  const yaw = estimateYaw(landmarks)
  const issues = assessFaceQuality(detection, landmarks, ear, width, height)

  return { state: 'ok', issues, ear, yaw }
}

/**
 * The full version — detection + landmarks + descriptor over every face in
 * the frame (not just the best one, see the multi-face check below), plus
 * the same capture-quality checks as detectFaceLive(). This is the one to
 * call once, at the moment of actually capturing/scanning — the same class
 * of checks real ID-verification / passport-photo flows run client-side
 * before ever treating a frame as usable: exactly one person in frame,
 * centered, a sensible size, fully inside the frame edges, roughly
 * front-facing, eyes open, and high enough detection confidence.
 *
 * None of this is a certified biometric or liveness system — it's
 * heuristic geometry over face-api.js's own landmark points, the same
 * open-source library gives any browser-based app. See createTurnTracker
 * below for what it does and doesn't defend against.
 *
 * @param {HTMLVideoElement|HTMLCanvasElement} input
 * @returns {Promise<{state: 'no-face'|'multiple-faces'|'ok', issues: string[], descriptor?: number[], ear?: number, yaw?: number}>}
 */
export async function analyzeFace(input) {
  const results = await faceapi.detectAllFaces(input, DETECTOR_OPTIONS).withFaceLandmarks().withFaceDescriptors()

  if (results.length === 0) {
    return { state: 'no-face', issues: ['No face detected. Make sure your face is visible and well lit.'] }
  }
  if (results.length > 1) {
    return { state: 'multiple-faces', issues: ['Multiple faces detected. Only one person should be in the frame.'] }
  }

  const [{ detection, landmarks, descriptor }] = results
  const { width, height } = frameSize(input)
  const ear = averageEAR(landmarks)
  const yaw = estimateYaw(landmarks)
  const issues = assessFaceQuality(detection, landmarks, ear, width, height)

  return { state: 'ok', issues, descriptor: Array.from(descriptor), ear, yaw }
}

function assessFaceQuality(detection, landmarks, ear, frameWidth, frameHeight) {
  const issues = []
  const box = detection.box

  if (detection.score < 0.7) {
    issues.push('Face not clear enough — improve lighting and hold steady.')
  }

  const centerX = box.x + box.width / 2
  const centerY = box.y + box.height / 2
  const offsetX = Math.abs(centerX - frameWidth / 2) / frameWidth
  const offsetY = Math.abs(centerY - frameHeight / 2) / frameHeight
  if (offsetX > 0.15 || offsetY > 0.15) {
    issues.push('Move to center your face in the frame.')
  }

  const widthRatio = box.width / frameWidth
  if (widthRatio < 0.22) {
    issues.push('Move closer to the camera.')
  } else if (widthRatio > 0.75) {
    issues.push('Move back a little — your face is too close.')
  }

  // "All parts of the face visible" — the box shouldn't touch/exceed the
  // frame edges, which is what happens when part of the face (chin, top of
  // head, side of face) is actually cropped out of the camera's view.
  const marginX = frameWidth * 0.02
  const marginY = frameHeight * 0.02
  if (box.x < marginX || box.y < marginY || box.x + box.width > frameWidth - marginX || box.y + box.height > frameHeight - marginY) {
    issues.push("Your full face isn't visible — move back into frame.")
  }

  if (ear < 0.15) {
    issues.push('Keep your eyes open.')
  }

  return issues
}

function averagePoint(points) {
  const x = points.reduce((sum, p) => sum + p.x, 0) / points.length
  const y = points.reduce((sum, p) => sum + p.y, 0) / points.length
  return { x, y }
}

// Standard 6-point eye-aspect-ratio (Soukupová & Čech, 2016) — face-api.js's
// getLeftEye()/getRightEye() return points in dlib's usual eye order
// [outer corner, top-1, top-2, inner corner, bottom-2, bottom-1]. Open eyes
// land around 0.25–0.35; closed eyes drop toward 0.1 or below. Only used
// for the static "keep your eyes open" quality check now — see
// createTurnTracker for the actual liveness check.
function eyeAspectRatio(eye) {
  const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y)
  const vertical = dist(eye[1], eye[5]) + dist(eye[2], eye[4])
  const horizontal = dist(eye[0], eye[3])
  return horizontal === 0 ? 0 : vertical / (2 * horizontal)
}

function averageEAR(landmarks) {
  return (eyeAspectRatio(landmarks.getLeftEye()) + eyeAspectRatio(landmarks.getRightEye())) / 2
}

/**
 * Signed left/right head-turn estimate, roughly -1..1, 0 = facing straight
 * on. As the head yaws, the nose tip shifts horizontally relative to the
 * eye-line's center while the eye-line itself barely moves — a standard,
 * simple pose proxy.
 *
 * Sign convention: positive = the wearer's own physical right, matching
 * what they see in the mirrored preview (video has `scaleX(-1)` applied
 * for display, the same way a real mirror reads — turn your head right,
 * your reflection also appears to turn right). face-api.js itself always
 * analyzes the *unmirrored* raw frame, so this negates the raw geometry to
 * land on that intuitive, on-screen-mirrored meaning. If it ever reads
 * backwards in practice, flip the leading `-` below — everything else
 * (createTurnTracker, the UI prompts) stays correct either way since they
 * only care about the sign, not the raw math.
 */
function estimateYaw(landmarks) {
  const leftEye = averagePoint(landmarks.getLeftEye())
  const rightEye = averagePoint(landmarks.getRightEye())
  const eyesCenterX = (leftEye.x + rightEye.x) / 2
  const interEyeDist = Math.hypot(leftEye.x - rightEye.x, leftEye.y - rightEye.y) || 1
  const nosePoints = landmarks.getNose()
  const noseTip = nosePoints[3] || nosePoints[nosePoints.length - 1]
  return -((noseTip.x - eyesCenterX) / interEyeDist)
}

/**
 * A lightweight liveness heuristic — NOT a certified anti-spoofing system.
 * It only looks for the head yawing past a threshold to BOTH sides (right
 * and left, in either order) across the live preview, which a still photo
 * held up to the camera can't produce. It does NOT defend against a
 * played-back video of the person turning their head, a 3D mask, or
 * similar presentation attacks — real liveness detection needs a certified
 * vendor SDK or depth-sensing hardware, neither of which this app has.
 * Treat this as a basic deterrent, not a security guarantee.
 *
 * Threshold is deliberately modest — this only needs to reliably notice a
 * natural head turn, not measure it precisely. The geometric checks in
 * assessFaceQuality() are the real gate.
 */
export function createTurnTracker(threshold = 0.18) {
  let turnedRight = false
  let turnedLeft = false
  return {
    get turnedRight() {
      return turnedRight
    },
    get turnedLeft() {
      return turnedLeft
    },
    get complete() {
      return turnedRight && turnedLeft
    },
    reset() {
      turnedRight = false
      turnedLeft = false
    },
    update(yaw) {
      if (yaw > threshold) turnedRight = true
      else if (yaw < -threshold) turnedLeft = true
    },
  }
}
