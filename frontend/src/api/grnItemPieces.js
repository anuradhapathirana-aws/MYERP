import api from './axios'

export const getGrnItemPiece = (pieceCode) =>
  api.get(`/api/v1/pieces/${pieceCode}`).then((r) => r.data.data)
