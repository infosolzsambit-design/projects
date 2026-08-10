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
// returns a plain number[] (128-d) or null if no face was found.
export async function getFaceDescriptor(input) {
  const result = await faceapi
    .detectSingleFace(input, DETECTOR_OPTIONS)
    .withFaceLandmarks()
    .withFaceDescriptor()
  return result ? Array.from(result.descriptor) : null
}

export function faceDistance(a, b) {
  return faceapi.euclideanDistance(a, b)
}

export function isMatch(a, b) {
  return faceDistance(a, b) <= MATCH_THRESHOLD
}
